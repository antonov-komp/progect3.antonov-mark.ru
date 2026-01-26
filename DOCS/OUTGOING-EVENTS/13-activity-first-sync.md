# Синхронная обработка ActivityFirst

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает синхронную обработку ActivityFirst: выполнение сразу после получения webhook-события в фоне после отправки ответа Bitrix24, использование `fastcgi_finish_request()`, rate limiting, прикрепление файлов к задаче и обновление файлов сделок.

---

## Общая схема

### Когда выполняется

Синхронная обработка ActivityFirst выполняется для событий `ONTASKCOMMENTADD`, когда:
1. Комментарий успешно получен и обработан
2. Условия ActivityFirst выполнены (`activityFirst = true`)
3. Включена синхронная обработка (`ACTIVITY_FIRST_SYNC_ENABLED = true`)

### Где выполняется

**Файл:** `outgoing-webhook/index.php`

**Точка выполнения:** после получения данных комментария, но до отправки ответа Bitrix24

**Код:**
```php
if ($commentWritten && is_array($details) && !empty($details['activityFirst'])) {
    // Отправляем ответ Bitrix24 сразу
    outgoingWebhookJsonResponse(200, ['status' => 'ok']);
    
    // Выполняем синхронную обработку в фоне
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        outgoingWebhookProcessActivityFirstSync($details, $entityId, $requestId);
    } else {
        register_shutdown_function(function() use ($details, $entityId, $requestId) {
            outgoingWebhookProcessActivityFirstSync($details, $entityId, $requestId);
        });
    }
    exit;
}
```

---

## Функция outgoingWebhookProcessActivityFirstSync

**Файл:** `outgoing-webhook/bootstrap.php`

### Назначение

Синхронная обработка ActivityFirst: прикрепление файлов к задаче и обновление файлов сделок.

### Параметры

- `$commentDetails` — детали комментария с `activityFirst = true`
- `$taskId` — ID задачи
- `$requestId` — ID запроса

### Возвращает

`true` при успехе, `false` при ошибке или пропуске

---

## Алгоритм обработки

### Шаг 1: Проверка конфигурации

```php
$enabled = outgoingWebhookGetSetting('ACTIVITY_FIRST_SYNC_ENABLED', 'true');
if ($enabled !== 'true' && $enabled !== '1') {
    return false; // Синхронная обработка выключена
}
```

**Настройка:** `ACTIVITY_FIRST_SYNC_ENABLED` (по умолчанию: `'true'`)

### Шаг 2: Проверка условий ActivityFirst

```php
if (empty($commentDetails['activityFirst'])) {
    return false; // Условия ActivityFirst не выполнены
}
```

### Шаг 3: Валидация данных

```php
$fileIds = $commentDetails['fileIds'] ?? [];
$dealIds = $commentDetails['crmLinks'] ?? [];
if (empty($fileIds) || empty($dealIds)) {
    // Логирование ошибки и возврат false
}
```

**Требования:**
- `fileIds` — массив ID файлов (не пустой)
- `dealIds` — массив ID сделок из CRM-связей (не пустой)

**Извлечение dealIds:**
```php
$dealIds = $this->taskDetails->extractDealIds($commentDetails['crmLinks'] ?? []);
```

### Шаг 4: Rate Limiting

```php
$rateLimit = (int) outgoingWebhookGetSetting('ACTIVITY_FIRST_SYNC_RATE_LIMIT', '5');
$lockFile = $stateDir . '/activity-first-sync.lock';
$lockHandle = fopen($lockFile, 'c+');

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Rate limit превышен — пропуск обработки
    return false;
}
```

**Механизм:**
- Файловая блокировка через `flock()` с `LOCK_EX | LOCK_NB` (неблокирующая)
- Если блокировка не получена — обработка пропускается (логируется как rate limit)
- Настройка: `ACTIVITY_FIRST_SYNC_RATE_LIMIT` (по умолчанию: `5`)

**Примечание:** Rate limiting ограничивает количество одновременных обработок, но не количество обработок в единицу времени.

### Шаг 5: Получение сервисов

```php
$services = outgoingWebhookGetSyncServices();
$restCall = fn(string $method, array $params = []) => $services['rest']->call($method, $params);
```

**Функция `outgoingWebhookGetSyncServices()`:**
- Создаёт все необходимые сервисы для синхронной обработки
- Включает: `RestService`, `CommentDetailsService`, `TaskDetailsService`, `TaskFilesService`, `DealFileService`

### Шаг 6: Обработка ActivityFirst

```php
$result = $services['commentDetailsService']->processActivityFirst(
    $commentDetails,
    $taskId,
    $restCall
);
```

**Метод `CommentDetailsService::processActivityFirst()`:**

1. **Валидация данных:**
   - Проверка `fileIds` (не пустой, все значения числовые)
   - Проверка `dealIds` (не пустой, все значения строковые)

2. **Прикрепление файлов к задаче:**
   ```php
   $taskAttach = $this->taskFiles->attachFiles($taskId, $fileIds, $restCall);
   ```
   - Метод REST API: `tasks.task.files.attach`
   - Пропуск файлов, уже прикреплённых к задаче
   - Обработка ошибок прикрепления

3. **Построение данных файлов для сделок:**
   ```php
   foreach ($fileIds as $fileId) {
       $fileData = $this->dealFiles->buildDealFileData($fileId, $restCall);
       if ($fileData !== null) {
           $fileDataList[] = ['fileData' => $fileData];
       }
   }
   ```
   - Получение информации о файле через `disk.file.get`
   - Загрузка файла в Base64 через `downloadBase64()`
   - Формат: `[имя_файла, base64_данные]`

4. **Обновление файлов сделок:**
   ```php
   foreach ($dealIds as $dealId) {
       $dealUpdates[] = $this->dealFiles->updateDealFiles(
           $dealId,
           'UF_CRM_1759233362672',
           $fileDataList,
           $restCall
       );
   }
   ```
   - Получение существующих файлов сделки
   - Добавление новых файлов
   - Обновление через `crm.deal.update`
   - **Важно:** все существующие файлы должны быть включены, иначе они будут удалены

**Формат результата:**
```json
{
  "dealIds": ["123", "456"],
  "fileIds": ["789", "999"],
  "taskAttach": {
    "attached": ["789", "999"],
    "errors": []
  },
  "dealUpdates": [
    {"dealId": "123", "success": true},
    {"dealId": "456", "success": true}
  ]
}
```

### Шаг 7: Маркировка как обработанного

```php
$services['taskDetails']->markActivityFirstProcessed($requestId, $taskId);
```

**Файл:** `state/activity-first-processed/{requestId}_{taskId}.json`

**Использование:** для предотвращения повторной обработки в очереди

### Шаг 8: Логирование результата

```php
$services['taskDetails']->logActivityFirst([
    'loggedAt' => $services['request']->now(),
    'requestId' => $requestId,
    'taskId' => $taskId,
    'sync' => true,
    'dealIds' => $result['dealIds'],
    'fileIds' => $result['fileIds'],
    'taskAttach' => $result['taskAttach'],
    'dealUpdates' => $result['dealUpdates'],
]);
```

**Файл:** `logs/activity-first.log`

**Формат:** line-based JSON

### Шаг 9: Логирование метрик

```php
$metrics = [
    'loggedAt' => $services['request']->now(),
    'requestId' => $requestId,
    'taskId' => $taskId,
    'sync' => true,
    'durationMs' => $durationMs,
    'success' => true,
    'filesCount' => count($result['fileIds']),
    'dealsCount' => count($result['dealIds']),
    'rateLimitHit' => false,
];
```

**Файл:** `logs/activity-first-metrics.log`

**Формат:** line-based JSON

**Метрики:**
- `durationMs` — время обработки в миллисекундах
- `success` — успешность обработки
- `filesCount` — количество файлов
- `dealsCount` — количество сделок
- `rateLimitHit` — был ли превышен rate limit

### Шаг 10: Освобождение блокировки

```php
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
```

---

## Использование fastcgi_finish_request()

### Назначение

`fastcgi_finish_request()` отправляет ответ клиенту и завершает соединение, но продолжает выполнение скрипта в фоне.

### Преимущества

1. **Быстрый ответ Bitrix24** — ответ отправляется сразу, не дожидаясь обработки
2. **Фоновая обработка** — обработка ActivityFirst выполняется после отправки ответа
3. **Нет таймаута** — обработка не ограничена таймаутом веб-сервера

### Fallback

Если `fastcgi_finish_request()` недоступна, используется `register_shutdown_function()`:

```php
register_shutdown_function(function() use ($details, $entityId, $requestId) {
    outgoingWebhookProcessActivityFirstSync($details, $entityId, $requestId);
});
```

**Ограничение:** обработка может быть прервана таймаутом веб-сервера.

---

## Rate Limiting

### Механизм

Файловая блокировка через `flock()` с флагом `LOCK_EX | LOCK_NB` (неблокирующая эксклюзивная блокировка).

### Настройка

**Настройка:** `ACTIVITY_FIRST_SYNC_RATE_LIMIT` (по умолчанию: `5`)

**Файл блокировки:** `state/activity-first-sync.lock`

### Поведение

- Если блокировка получена — обработка выполняется
- Если блокировка не получена — обработка пропускается (логируется как rate limit)

**Примечание:** Rate limiting ограничивает количество одновременных обработок, но не количество обработок в единицу времени. Если обработка завершается быстро, блокировка освобождается, и следующая обработка может начаться.

---

## Обработка ошибок

### Валидация данных

```php
if (empty($fileIds) || empty($dealIds)) {
    outgoingWebhookLogError('ActivityFirst sync: missing required data', [
        'requestId' => $requestId,
        'taskId' => $taskId,
        'fileIds' => $fileIds,
        'dealIds' => $dealIds,
    ]);
    return false;
}
```

### Ошибки обработки

```php
try {
    $result = $services['commentDetailsService']->processActivityFirst(...);
    // ...
} catch (Throwable $e) {
    outgoingWebhookLogError('ActivityFirst sync processing failed', [
        'requestId' => $requestId,
        'taskId' => $taskId,
        'message' => $e->getMessage(),
        'durationMs' => $durationMs,
    ]);
    
    // Логирование метрик ошибки
    $metrics = [
        'success' => false,
        'error' => $e->getMessage(),
    ];
    // ...
    return false;
}
```

**Логирование:**
- Ошибки логируются в `logs/errors/error-YYYYMMDD.log`
- Метрики ошибок логируются в `logs/activity-first-metrics.log`

---

## Предотвращение повторной обработки

### Маркировка обработанных событий

```php
$services['taskDetails']->markActivityFirstProcessed($requestId, $taskId);
```

**Файл:** `state/activity-first-processed/{requestId}_{taskId}.json`

**Формат:**
```json
{
  "requestId": "abc123...",
  "taskId": "123",
  "processedAt": "2026-01-26T12:00:00+03:00",
  "sync": true
}
```

### Проверка в очереди

В `QueueRunner` проверяется, была ли выполнена синхронная обработка:

```php
if ($this->taskDetails->isActivityFirstProcessed($requestId, $entityId)) {
    // Событие уже обработано синхронно, пропускаем
    $this->jobState->markDone($processingJob);
    // ...
    continue;
}
```

**Результат:** задание помечается как `done` без повторной обработки.

---

## Метрики производительности

### Логирование метрик

**Файл:** `logs/activity-first-metrics.log`

**Формат записи (успех):**
```json
{
  "loggedAt": "2026-01-26T12:00:00+03:00",
  "requestId": "abc123...",
  "taskId": "123",
  "sync": true,
  "durationMs": 1500,
  "success": true,
  "filesCount": 2,
  "dealsCount": 1,
  "rateLimitHit": false
}
```

**Формат записи (ошибка):**
```json
{
  "loggedAt": "2026-01-26T12:00:00+03:00",
  "requestId": "abc123...",
  "taskId": "123",
  "sync": true,
  "durationMs": 500,
  "success": false,
  "error": "Invalid fileId: abc"
}
```

**Формат записи (rate limit):**
```json
{
  "loggedAt": "2026-01-26T12:00:00+03:00",
  "requestId": "abc123...",
  "taskId": "123",
  "sync": true,
  "durationMs": 0,
  "success": false,
  "error": "rate_limit_exceeded",
  "rateLimitHit": true
}
```

### Анализ метрик

**Типичное время обработки:**
- Прикрепление файлов к задаче: 200-500 мс на файл
- Загрузка файла в Base64: 100-300 мс на файл
- Обновление файлов сделки: 300-800 мс на сделку

**Общее время:** 1-3 секунды для типичного случая (2 файла, 1 сделка)

---

## Конфигурация

### ACTIVITY_FIRST_SYNC_ENABLED

**Тип:** `string` (`'true'` или `'false'`)  
**По умолчанию:** `'true'`  
**Описание:** Включение синхронной обработки ActivityFirst

**Пример:**
```env
ACTIVITY_FIRST_SYNC_ENABLED=true
```

### ACTIVITY_FIRST_SYNC_RATE_LIMIT

**Тип:** `int`  
**По умолчанию:** `5`  
**Описание:** Лимит одновременных синхронных обработок (через lock-файл)

**Пример:**
```env
ACTIVITY_FIRST_SYNC_RATE_LIMIT=5
```

**Примечание:** Если обработка завершается быстро, блокировка освобождается, и следующая обработка может начаться. Это не ограничивает количество обработок в единицу времени, а только количество одновременных обработок.

---

## Связанные документы

- `04-activity-first.md` — логика ActivityFirst (условия срабатывания)
- `09-task-services.md` — сервисы задач (CommentDetailsService, TaskFilesService, DealFileService)
- `17-configuration.md` — конфигурация модуля

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием синхронной обработки ActivityFirst.
