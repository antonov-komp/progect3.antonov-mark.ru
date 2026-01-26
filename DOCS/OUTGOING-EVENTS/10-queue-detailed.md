# Очередь обработки событий: детальное описание

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает детали работы файловой очереди обработки событий: структуру, формат заданий, жизненный цикл, обработку ошибок, восстановление зависших заданий и запуск обработчиков.

---

## Структура очереди

### Директории

Очередь организована в виде четырёх директорий:

```
outgoing-webhook/queue/
├── pending/      # Задания, ожидающие обработки
├── processing/   # Задания, находящиеся в обработке
├── done/         # Успешно обработанные задания
└── failed/       # Задания, завершившиеся с ошибкой
```

### Именование файлов заданий

Формат имени файла: `{YYYYMMDD}_{HHMMSS}_{EVENT_TYPE}_{ENTITY_ID}.json`

**Примеры:**
- `20260126_120000_ONTASKADD_123.json`
- `20260126_120530_ONCRMDEALUPDATE_456.json`
- `20260126_121000_ONTASKCOMMENTADD_789.json`

**Компоненты:**
- `YYYYMMDD` — дата создания (год, месяц, день)
- `HHMMSS` — время создания (час, минута, секунда)
- `EVENT_TYPE` — тип события (например, `ONTASKADD`)
- `ENTITY_ID` — ID сущности или `'unknown'` если ID не определён

---

## Формат задания очереди

### Структура JSON-файла задания

```json
{
  "requestId": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
  "eventType": "ONTASKADD",
  "entityType": "task",
  "entityId": "123",
  "rawPath": "/var/www/.../outgoing-webhook/logs/ONTASKADD/raw.json",
  "createdAt": "2026-01-26T12:00:00+03:00",
  "attempt": 0,
  "priority": "normal",
  "source": "outgoing-webhook",
  "tokenSource": "auth.application_token",
  "eventHandlerId": "handler-123",
  "memberId": "member-456",
  "payload": {
    "event": "ONTASKADD",
    "token": "****1234",
    "data": {
      "FIELDS": {
        "ID": "123",
        "TITLE": "Task title"
      }
    }
  }
}
```

### Описание полей

| Поле | Тип | Описание |
|------|-----|----------|
| `requestId` | string | Уникальный ID запроса (32 hex-символа) |
| `eventType` | string | Тип события (например, `ONTASKADD`) |
| `entityType` | string | Тип сущности (например, `task`, `deal`) |
| `entityId` | string\|null | ID сущности или `null` |
| `rawPath` | string | Путь к файлу `raw.json` |
| `createdAt` | string | Время создания задания (ISO 8601) |
| `attempt` | int | Номер попытки обработки (начинается с 0) |
| `priority` | string | Приоритет задания (по умолчанию: `normal`) |
| `source` | string | Источник задания (по умолчанию: `outgoing-webhook`) |
| `tokenSource` | string | Источник токена (например, `auth.application_token`) |
| `eventHandlerId` | string | ID обработчика события |
| `memberId` | string | ID участника Bitrix24 |
| `payload` | array | Маскированный payload события (опционально) |

**Примечание:** Поле `payload` может отсутствовать, если данные хранятся в `rawPath`.

---

## Жизненный цикл задания

### 1. Создание задания (index.php)

При получении события от Bitrix24:

1. Валидация запроса (токен, IP, размер payload)
2. Нормализация `eventType`
3. Извлечение `entityId` и определение `entityType`
4. Запись `raw.json` и `event.log`
5. Создание задания в `queue/pending/`

**Код создания задания:**
```php
$queueItem = [
    'requestId' => $requestId,
    'eventType' => $eventType,
    'entityType' => $entityType,
    'entityId' => $entityId,
    'rawPath' => $rawPath,
    'createdAt' => outgoingWebhookNow(),
    'attempt' => 0,
    'priority' => 'normal',
    'source' => 'outgoing-webhook',
    'tokenSource' => $authInfo['source'],
    'eventHandlerId' => $eventHandlerId,
    'memberId' => $memberId,
    'payload' => $maskedPayload,
];

$queueName = sprintf(
    '%s_%s_%s.json',
    date('Ymd_His'),
    $eventType,
    $entityId ?? 'unknown'
);
$queuePath = __DIR__ . '/queue/pending/' . $queueName;
outgoingWebhookWriteJson($queuePath, $queueItem);
```

### 2. Обработка задания (QueueRunner)

#### Шаг 1: Восстановление зависших заданий

```php
$this->jobState->recoverProcessing();
```

- Поиск заданий в `processing/` старше таймаута (15 минут)
- Возврат в `pending/` или перемещение в `failed/` (если попытки исчерпаны)

#### Шаг 2: Получение списка заданий

```php
$pendingJobs = $this->queue->listPending($limit);
```

- Получение до `$limit` заданий из `pending/`
- Сортировка по имени файла (хронологическая)

#### Шаг 3: Перевод в состояние processing

```php
$processingJob = $this->jobState->markProcessing($pendingJob);
```

- Перемещение файла из `pending/` в `processing/`
- Создание нового объекта `QueueJob`

#### Шаг 4: Увеличение счётчика попыток

```php
$job['attempt'] = (int) ($job['attempt'] ?? 0) + 1;
$processingJob->setData($job);
```

#### Шаг 5: Извлечение данных

```php
$raw = [];
if (isset($job['payload']) && is_array($job['payload'])) {
    $raw = ['payload' => $job['payload']];
} elseif ($rawPath !== '' && file_exists($rawPath)) {
    $raw = json_decode(file_get_contents($rawPath), true);
}
```

**Приоритет источников:**
1. Поле `payload` в задании
2. Файл `rawPath`

#### Шаг 6: Обогащение данных

```php
$enriched = $this->enrichment->buildEnriched(
    $eventType,
    $entityType,
    $entityId,
    $raw,
    $rawPath
);
```

- Вызов соответствующего REST-метода Bitrix24
- Загрузка справочников (воронки, стадии)
- Сохранение в `enriched.json`

#### Шаг 7: Обработка ошибок обогащения

```php
if (!empty($enriched['error'])) {
    if ($job['attempt'] >= $this->jobState->getMaxAttempts()) {
        // Перемещение в failed/
        $this->jobState->markFailed($processingJob, $enriched['error']);
    } else {
        // Возврат в pending/
        rename($processingJob->getPath(), $this->queue->getPendingDir() . '/' . $processingJob->getName());
    }
    continue;
}
```

#### Шаг 8: Сохранение обогащённых данных

```php
$enrichedPath = $eventDir . '/enriched.json';
outgoingWebhookWriteJson($enrichedPath, $enriched);
```

#### Шаг 9: Обработка задач и комментариев

```php
$taskData = $this->taskDetails->writeDetails($eventType, $enriched, $requestId, $entityId);

if ($eventType === 'ONTASKCOMMENTADD') {
    // Проверка синхронной обработки
    if (!$this->taskDetails->isActivityFirstProcessed($requestId, $entityId)) {
        $this->commentDetails->handleCommentAdd(...);
    }
}
```

#### Шаг 10: Отслеживание изменений полей

```php
if ($entityId !== null && isset($enriched['data'][$entityType])) {
    $this->enrichment->detectFieldChanges(
        $entityType,
        $entityId,
        $enriched['data'][$entityType],
        $eventType
    );
}
```

#### Шаг 11: Завершение обработки

```php
$this->jobState->markDone($processingJob);
```

- Перемещение файла из `processing/` в `done/`

### 3. Результат обработки

#### Успешная обработка

- Файл перемещается в `queue/done/`
- Данные сохраняются в `enriched.json`
- Логируется завершение в `queue-steps.log`

#### Ошибка обработки

- Если попытки не исчерпаны — файл возвращается в `queue/pending/`
- Если попытки исчерпаны — файл перемещается в `queue/failed/`
- Создаётся файл `.error.json` с деталями ошибки

**Формат `.error.json`:**
```json
{
  "failedAt": "2026-01-26T12:05:00+03:00",
  "reason": "rest_error",
  "attempt": 3,
  "lastMethod": "crm.deal.get"
}
```

---

## Обработка ошибок

### Повторные попытки

**Максимальное количество попыток:** `OUTGOING_WEBHOOK_MAX_ATTEMPTS` (по умолчанию: `3`)

**Логика:**
1. При ошибке обогащения счётчик `attempt` увеличивается
2. Если `attempt < maxAttempts` — задание возвращается в `pending/`
3. Если `attempt >= maxAttempts` — задание перемещается в `failed/`

**Пример:**
```php
// Попытка 1: ошибка → attempt = 1 → возврат в pending/
// Попытка 2: ошибка → attempt = 2 → возврат в pending/
// Попытка 3: ошибка → attempt = 3 → перемещение в failed/
```

### Восстановление зависших заданий

**Таймаут обработки:** `OUTGOING_WEBHOOK_PROCESSING_TIMEOUT` (по умолчанию: `900` секунд = 15 минут)

**Алгоритм восстановления:**
1. Поиск всех файлов в `processing/`
2. Для каждого файла проверка возраста (время модификации)
3. Если возраст > таймаута:
   - Увеличение счётчика попыток
   - Если попытки исчерпаны — перемещение в `failed/` с причиной `'processing_timeout'`
   - Иначе — перемещение обратно в `pending/`

**Вызов:** автоматически при каждом запуске обработчика очереди

```php
$this->jobState->recoverProcessing();
```

---

## Запуск обработчиков очереди

### HTTP-обработчик (process-queue.php)

**Файл:** `outgoing-webhook/tools/process-queue.php`

**Использование:** через HTTP-запрос (например, cron через wget/curl)

**Параметры:**
- `?limit=50` — максимальное количество заданий (по умолчанию: `50`)

**Пример:**
```bash
curl "https://your-domain.com/outgoing-webhook/tools/process-queue.php?limit=100"
```

**Ответ:**
```json
{
  "processed": 10,
  "processingMs": 5000,
  "queue": {
    "pending": 5,
    "processing": 0,
    "done": 100,
    "failed": 2
  }
}
```

### CLI-обработчик (process-queue-cli.php)

**Файл:** `outgoing-webhook/tools/process-queue-cli.php`

**Использование:** через командную строку (для cron)

**Параметры:**
- `--limit=50` — максимальное количество заданий (по умолчанию: `50`)

**Пример:**
```bash
php outgoing-webhook/tools/process-queue-cli.php --limit=100
```

**Пример cron:**
```cron
# Обработка очереди каждые 5 минут
*/5 * * * * cd /var/www/progect3.antonov-mark.ru && php outgoing-webhook/tools/process-queue-cli.php --limit=50 >> /var/log/queue-processor.log 2>&1
```

---

## Мониторинг очереди

### Подсчёт заданий

```php
$services = outgoingWebhookGetServices();
$queue = $services['queue'];

$pending = $queue->count($queue->getPendingDir());
$processing = $queue->count($queue->getProcessingDir());
$done = $queue->count($queue->getDoneDir());
$failed = $queue->count($queue->getFailedDir());
```

### Логирование шагов

Все шаги обработки логируются в `logs/queue-steps.log`:

**Формат записи:**
```json
{"loggedAt":"2026-01-26T12:00:00+03:00","step":"started","requestId":"abc123...","eventType":"ONTASKADD","entityType":"task","entityId":"123","attempt":1,"jobFile":"20260126_120000_ONTASKADD_123.json"}
{"loggedAt":"2026-01-26T12:00:05+03:00","step":"finished","requestId":"abc123...","eventType":"ONTASKADD","entityType":"task","entityId":"123","attempt":1,"jobFile":"20260126_120000_ONTASKADD_123.json","status":"done"}
```

---

## Рекомендации по настройке

### Частота запуска обработчика

- **Рекомендуется:** каждые 1-5 минут
- **Зависит от:** объёма событий и производительности сервера

### Лимит заданий за запуск

- **Рекомендуется:** 50-100 заданий
- **Зависит от:** времени обработки одного задания и таймаута PHP

### Максимальное количество попыток

- **По умолчанию:** 3
- **Рекомендуется:** 3-5 попыток

### Таймаут обработки

- **По умолчанию:** 900 секунд (15 минут)
- **Рекомендуется:** 2-3 раза больше среднего времени обработки задания

---

## Очистка старых заданий

### Рекомендации

- **done/:** можно удалять задания старше 30 дней
- **failed/:** рекомендуется хранить для анализа ошибок (минимум 7 дней)
- **pending/:** не должно быть старых заданий (если есть — проблема с обработчиком)

**Пример скрипта очистки:**
```bash
#!/bin/bash
# Очистка заданий старше 30 дней из done/
find /var/www/.../outgoing-webhook/queue/done/ -name "*.json" -mtime +30 -delete
```

---

## Связанные документы

- `08-queue-services.md` — описание сервисов очереди
- `07-enrichment-services.md` — сервисы обогащения
- `09-task-services.md` — сервисы задач
- `01-how-it-works.md` — общий поток обработки

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с детальным описанием очереди.
