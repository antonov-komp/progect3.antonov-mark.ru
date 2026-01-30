# Обработка ошибок в модуле outgoing-webhook

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает стратегию обработки ошибок в модуле `outgoing-webhook`: классификация ошибок, логирование, обработка ошибок REST API, файловых операций, очереди и восстановление после ошибок.

---

## Классификация ошибок

### По уровню критичности

1. **Критические ошибки** — прерывают выполнение
   - Неверный токен
   - IP не разрешён
   - Payload слишком большой
   - Отсутствует обязательная конфигурация

2. **Ошибки обработки** — не прерывают основной поток
   - Ошибка REST API при обогащении
   - Ошибка записи файла
   - Ошибка обработки очереди

3. **Предупреждения** — не критичны, но требуют внимания
   - Ошибка синхронизации с Bitrix24
   - Ошибка загрузки справочника
   - Rate limit превышен

### По источнику

1. **Ошибки валидации** — неверные входные данные
2. **Ошибки безопасности** — проблемы с токеном, IP
3. **Ошибки REST API** — проблемы с Bitrix24 API
4. **Ошибки файловых операций** — проблемы с записью/чтением файлов
5. **Ошибки обработки очереди** — проблемы с заданиями очереди

---

## Стратегия обработки ошибок

### Принципы

1. **Не прерывать основной процесс** — ошибки не должны ломать обработку других событий
2. **Логировать все ошибки** — для анализа и отладки
3. **Возвращать понятные сообщения** — для диагностики
4. **Восстанавливаться после ошибок** — повторные попытки, восстановление зависших заданий

---

## Обработка ошибок в index.php

### Валидация запроса

```php
// Проверка метода
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    outgoingWebhookJsonResponse(405, ['error' => 'method_not_allowed']);
    exit;
}

// Проверка размера payload
if ($contentLength > OUTGOING_WEBHOOK_MAX_BYTES) {
    outgoingWebhookJsonResponse(413, ['error' => 'payload_too_large']);
    exit;
}

// Проверка payload
if (empty($payload)) {
    outgoingWebhookLogError('Empty or invalid payload', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    outgoingWebhookJsonResponse(400, ['error' => 'invalid_payload']);
    exit;
}
```

**Ошибки:**
- `405` — метод не разрешён
- `413` — payload слишком большой
- `400` — неверный payload

### Валидация IP

```php
$allowedIps = outgoingWebhookGetAllowedIps();
if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
    outgoingWebhookLogError('IP not allowed', ['ip' => $clientIp, 'requestId' => $requestId]);
    outgoingWebhookJsonResponse(403, ['error' => 'ip_not_allowed']);
    exit;
}
```

**Ошибка:** `403` — IP не разрешён

### Валидация токена

```php
$expectedToken = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN');
if ($expectedToken === null) {
    outgoingWebhookLogError('Missing OUTGOING_WEBHOOK_TOKEN env');
    outgoingWebhookJsonResponse(500, ['error' => 'server_not_configured']);
    exit;
}

$authInfo = outgoingWebhookExtractAuthInfo($payload);
if (!hash_equals($expectedToken, $authInfo['token'])) {
    outgoingWebhookLogError('Invalid token', [
        'ip' => $clientIp,
        'requestId' => $requestId,
        'token_source' => $authInfo['source'],
    ]);
    outgoingWebhookJsonResponse(403, ['error' => 'invalid_token']);
    exit;
}
```

**Ошибки:**
- `500` — сервер не настроен (отсутствует токен)
- `403` — неверный токен

**Безопасность:** используется `hash_equals()` для сравнения токенов (защита от timing attacks)

### Ошибки записи файлов

```php
if (!outgoingWebhookWriteJson($rawPath, $raw)) {
    outgoingWebhookLogError('Failed to write raw.json', ['path' => $rawPath]);
}

if (!outgoingWebhookAppendLine($eventLogPath, $eventLogLine)) {
    outgoingWebhookLogError('Failed to write event.log', ['path' => $eventLogPath]);
}

if (!outgoingWebhookWriteJson($queuePath, $queueItem)) {
    outgoingWebhookLogError('Failed to enqueue item', ['path' => $queuePath]);
}
```

**Особенность:** ошибки логируются, но не прерывают выполнение (задание всё равно создаётся)

### Ошибки REST API для задач

```php
try {
    $result = $client->call('tasks.task.get', ['id' => $entityId]);
    if (!empty($result['error'])) {
        outgoingWebhookLogError('Task details REST error', [
            'requestId' => $requestId,
            'taskId' => $entityId,
            'error' => $result['error'],
        ]);
    } else {
        // Обработка успешного результата
    }
} catch (Throwable $e) {
    outgoingWebhookLogError('Task details exception', [
        'requestId' => $requestId,
        'taskId' => $entityId,
        'message' => $e->getMessage(),
    ]);
}
```

**Особенность:** ошибки логируются, но не прерывают создание задания в очереди

---

## Обработка ошибок в очереди

### Ошибки обогащения

```php
$enriched = $this->enrichment->buildEnriched($eventType, $entityType, $entityId, $raw, $rawPath);
if (!empty($enriched['error'])) {
    if ($job['attempt'] >= $this->jobState->getMaxAttempts()) {
        // Перемещение в failed/
        $this->jobState->markFailed($processingJob, $enriched['error'], $enriched['details'] ?? null);
    } else {
        // Возврат в pending/ для повторной попытки
        outgoingWebhookWriteJson($processingJob->getPath(), $job);
        rename($processingJob->getPath(), $this->queue->getPendingDir() . '/' . $processingJob->getName());
    }
    continue;
}
```

**Стратегия:**
- Если попытки не исчерпаны — возврат в `pending/` с увеличенным счётчиком
- Если попытки исчерпаны — перемещение в `failed/` с записью ошибки

### Ошибки записи enriched.json

```php
if (!outgoingWebhookWriteJson($enrichedPath, $enriched)) {
    $this->errors->log('Failed to write enriched.json', ['path' => $enrichedPath]);
}
```

**Особенность:** ошибка логируется, но задание помечается как `done` (данные уже обогащены в памяти)

### Восстановление зависших заданий

```php
$this->jobState->recoverProcessing();
```

**Алгоритм:**
1. Поиск заданий в `processing/` старше таймаута
2. Увеличение счётчика попыток
3. Если попытки исчерпаны — перемещение в `failed/`
4. Иначе — возврат в `pending/`

**Таймаут:** `OUTGOING_WEBHOOK_PROCESSING_TIMEOUT` (по умолчанию: 900 секунд)

---

## Обработка ошибок REST API

### RestService

```php
public function call(string $method, array $params = []): array
{
    $retries = (int) ($this->config->get('OUTGOING_WEBHOOK_REST_RETRIES', '0') ?? 0);
    $delayMs = (int) ($this->config->get('OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS', '0') ?? 0);
    $attempt = 0;

    do {
        $attempt++;
        $result = $this->client->call($method, $params);

        if (empty($result['error'])) {
            return $result;
        }

        if ($attempt <= $retries) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            continue;
        }

        $this->errors->log('REST call failed', [
            'method' => $method,
            'error' => $result['error'],
            'error_information' => $result['error_information'] ?? null,
        ]);

        return $result;
    } while ($attempt <= $retries);
}
```

**Стратегия:**
- Повторные попытки при ошибке (настраивается через `OUTGOING_WEBHOOK_REST_RETRIES`)
- Задержка между попытками (настраивается через `OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS`)
- Логирование ошибки после исчерпания попыток

### Типичные ошибки REST API

1. **invalid_token** — неверный токен
2. **NOT_FOUND** — сущность не найдена
3. **ACCESS_DENIED** — нет доступа
4. **QUERY_LIMIT_EXCEEDED** — превышен лимит запросов
5. **INTERNAL_SERVER_ERROR** — внутренняя ошибка Bitrix24
6. **ERROR_CORE** — внутренняя/ядровая ошибка Bitrix24 (часто без детализации в `error_information`)

#### ERROR_CORE при обработке очереди комментариев

**Контекст:** событие ONTASKCOMMENTADD по сути — это **сообщение в чате, привязанном к задаче**. В коде приоритет отдан чату задачи (`im.dialog.messages.get`); устаревший формат `task.commentitem.get` используется только как fallback и в облаке часто даёт ERROR_CORE.

При запуске `process-queue-cli.php` для ONTASKCOMMENTADD могут наблюдаться ошибки:

- **task.commentitem.get** / **task.commentitem.getlist** — ERROR_CORE (устаревший API комментариев задачи)
- **task.item.getdata** — ERROR_CORE (данные задачи)

**Поведение в коде:**

- **Сначала** запрашивается сообщение через чат задачи (`im.dialog.messages.get`) по `messageId` и `chatId` из данных задачи.
- **Только при отсутствии** messageId/chatId или если сообщение не найдено в чате — вызывается fallback `task.commentitem.get` / getlist.
- Чат возвращает последние ~50 сообщений; если нужное сообщение старше — будет «message not found».
- При отсутствии данных пишется fallback в comment_details и в лог: `Comment details missing`. Activity не выполняется без полных деталей (fileIds, crmLinks, activityType).

**Рекомендации:** проверять scope вебхука (чат, задачи); при постоянном ERROR_CORE по task.* — причина на стороне Bitrix24 или прав доступа.

---

## Обработка ошибок файловых операций

### FilesystemService

```php
public function writeJson(string $path, array $data): bool
{
    $this->ensureDir(dirname($path));

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(['error' => 'json_encode_failed']);
    }

    return file_put_contents($path, $json . PHP_EOL) !== false;
}
```

**Обработка ошибок:**
- При ошибке `json_encode` записывается fallback JSON
- Возврат `false` при ошибке записи

```php
public function appendLine(string $path, string $line): bool
{
    $this->ensureDir(dirname($path));

    $handle = @fopen($path, 'a');
    if ($handle === false) {
        return false;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }

    $result = fwrite($handle, $line . PHP_EOL);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result !== false;
}
```

**Обработка ошибок:**
- Использование `@fopen()` для подавления предупреждений
- Проверка блокировки файла
- Явная синхронизация через `fflush()`

---

## Логирование ошибок

### ErrorService

**Файл:** `logs/errors/error-YYYYMMDD.log`

**Формат:** line-based JSON

**Пример записи:**
```json
{
  "loggedAt": "2026-01-26T12:00:00+03:00",
  "message": "REST call failed",
  "context": {
    "method": "crm.deal.get",
    "error": "invalid_token",
    "error_information": "..."
  }
}
```

**Дублирование:**
- Запись в файл через `FilesystemService::appendLine()`
- Запись в `error_log` через `error_log()`

### Формат логов ошибок

**Структура записи:**
```json
{
  "loggedAt": "2026-01-26T12:00:00+03:00",
  "message": "Описание ошибки",
  "context": {
    "key1": "value1",
    "key2": "value2"
  }
}
```

**Контекст обычно включает:**
- `requestId` — ID запроса
- `eventType` — тип события
- `entityId` — ID сущности
- `method` — метод REST API (если применимо)
- `error` — описание ошибки

---

## Восстановление после ошибок

### Восстановление зависших заданий

**Вызов:** автоматически при каждом запуске обработчика очереди

```php
$this->jobState->recoverProcessing();
```

**Алгоритм:**
1. Поиск заданий в `processing/` старше таймаута
2. Увеличение счётчика попыток
3. Если попытки исчерпаны — перемещение в `failed/`
4. Иначе — возврат в `pending/` для повторной обработки

### Повторные попытки

**Максимальное количество попыток:** `OUTGOING_WEBHOOK_MAX_ATTEMPTS` (по умолчанию: `3`)

**Логика:**
- При ошибке обогащения задание возвращается в `pending/` с увеличенным счётчиком
- Если попытки исчерпаны — задание перемещается в `failed/`

### Анализ failed-заданий

**Файлы:**
- `queue/failed/{job}.json` — данные задания
- `queue/failed/{job}.json.error.json` — детали ошибки

**Формат `.error.json`:**
```json
{
  "failedAt": "2026-01-26T12:00:00+03:00",
  "reason": "rest_error",
  "attempt": 3,
  "lastMethod": "crm.deal.get"
}
```

---

## Рекомендации по обработке ошибок

### Мониторинг ошибок

1. **Регулярная проверка логов:**
   ```bash
   tail -f outgoing-webhook/logs/errors/error-$(date +%Y%m%d).log
   ```

2. **Анализ failed-заданий:**
   ```bash
   ls -la outgoing-webhook/queue/failed/
   ```

3. **Проверка метрик:**
   ```bash
   tail -f outgoing-webhook/logs/activity-first-metrics.log
   ```

### Обработка типичных ошибок

1. **invalid_token:**
   - Проверить настройку `OUTGOING_WEBHOOK_TOKEN`
   - Проверить, что токен в Bitrix24 не изменился

2. **QUERY_LIMIT_EXCEEDED:**
   - Увеличить задержку между запросами
   - Уменьшить частоту запуска обработчика очереди

3. **NOT_FOUND:**
   - Сущность могла быть удалена
   - Проверить, что `entityId` корректный

4. **Ошибки записи файлов:**
   - Проверить права доступа к директориям
   - Проверить свободное место на диске

---

## Связанные документы

- `06-core-services.md` — базовые сервисы (ErrorService)
- `08-queue-services.md` — сервисы очереди (обработка ошибок)
- `17-configuration.md` — конфигурация (настройки повторных попыток)

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием обработки ошибок.
