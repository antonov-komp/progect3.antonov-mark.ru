# TASK-019: Синхронная обработка ActivityFirst для комментариев задач

**Дата создания:** 2026-01-23 22:15 (UTC+3, Брест)  
**Статус:** draft  
**Приоритет:** high  
**Исполнитель:** Bitrix24 Программист (PHP)

## Описание

Реализовать синхронную обработку событий с `ActivityFirst=да` сразу после получения webhook-события `ONTASKCOMMENTADD`, без ожидания обработки через очередь. Это обеспечит мгновенное прикрепление файлов к задачам и обновление файлов в связанных сделках.

## Контекст

**Текущая ситуация:**
- События `ONTASKCOMMENTADD` попадают в очередь `queue/pending/`
- Обработка очереди происходит асинхронно через cron (каждую минуту)
- Обработка `ActivityFirst` выполняется в `CommentDetailsService->handleCommentAdd()` только после обработки задания из очереди
- Задержка между событием и обработкой может составлять до 1 минуты

**Проблема:**
- Пользователи ожидают мгновенного обновления файлов в сделках
- Задержка в 1 минуту создаёт плохой UX
- Критичные операции (обновление файлов в CRM) должны выполняться синхронно

**Требование:**
- Обработка `ActivityFirst` должна выполняться синхронно в момент получения webhook-события
- Ответ Bitrix24 не должен задерживаться (обработка после отправки ответа или в фоне)
- Обработка через очередь должна остаться как fallback для случаев, когда синхронная обработка не удалась

## Модули и компоненты

### Файлы для изменения:

1. **`outgoing-webhook/index.php`** (основной endpoint)
   - Добавить синхронную обработку ActivityFirst после определения условий
   - Интегрировать вызов обработки после построения `commentDetails`

2. **`outgoing-webhook/bootstrap.php`** (вспомогательные функции)
   - Добавить функцию `outgoingWebhookGetSyncServices()` для получения сервисов синхронной обработки
   - Добавить функцию `outgoingWebhookProcessActivityFirstSync()` для синхронной обработки
   - Функция должна использовать существующую логику из `CommentDetailsService`

3. **`outgoing-webhook/services/Task/CommentDetailsService.php`** (сервис обработки комментариев)
   - Вынести логику обработки ActivityFirst в отдельный метод `processActivityFirst()`
   - Метод должен быть переиспользуемым для синхронной и асинхронной обработки
   - Добавить валидацию входных данных в метод

4. **`outgoing-webhook/services/Task/TaskDetailsService.php`** (сервис деталей задач)
   - Метод `evaluateActivityFirst()` уже существует — используется как есть
   - Метод `logActivityFirst()` уже существует — используется как есть
   - Добавить метод `markActivityFirstProcessed(string $requestId, string $taskId): void` для маркировки обработанных событий

5. **`outgoing-webhook/services/Queue/QueueRunner.php`** (обработчик очереди)
   - Добавить проверку, не была ли уже выполнена синхронная обработка перед асинхронной
   - Пропускать обработку, если событие уже обработано синхронно

### Новые файлы:

1. **`outgoing-webhook/logs/activity-first-metrics.log`** (метрики обработки)
   - Логирование метрик синхронной обработки (время выполнения, количество успешных/неуспешных)
   - Формат: JSON Lines

2. **`outgoing-webhook/state/activity-first-processed/`** (состояние обработанных событий)
   - Хранение информации о синхронно обработанных событиях
   - Формат: JSON файлы с ключом `requestId_taskId`

## Зависимости

- Использует существующую логику из `CommentDetailsService->handleCommentAdd()`
- Использует `TaskDetailsService->evaluateActivityFirst()` для определения условий
- Использует `TaskFilesService->attachFiles()` для прикрепления файлов
- Использует `DealFileService->updateDealFiles()` для обновления файлов в сделках
- Использует `Bitrix24Client` для REST API вызовов
- Использует `StateStorage` для хранения состояния обработанных событий
- Использует переменную окружения `ACTIVITY_FIRST_SYNC_ENABLED` для включения/отключения синхронной обработки

## Ступенчатые подзадачи

1. **Вынести логику обработки ActivityFirst в отдельный метод**
   - В `CommentDetailsService` создать метод `processActivityFirst(array $commentDetails, string $entityId, callable $restCall): array`
   - Перенести логику из `handleCommentAdd()` (строки 141-176) в новый метод
   - Метод должен возвращать результат обработки для логирования

2. **Создать функцию получения сервисов для синхронной обработки**
   - Создать функцию `outgoingWebhookGetSyncServices(): array` в `bootstrap.php`
   - Функция должна возвращать массив сервисов, необходимых для синхронной обработки
   - Использовать новый `Bitrix24Client` (не переиспользовать из `index.php`)
   - Кешировать сервисы в статической переменной для оптимизации

3. **Создать функцию синхронной обработки в bootstrap.php**
   - Создать функцию `outgoingWebhookProcessActivityFirstSync(array $commentDetails, string $taskId, string $requestId): bool`
   - Функция должна:
     - Проверять конфигурационный флаг `ACTIVITY_FIRST_SYNC_ENABLED` (по умолчанию `true`)
     - Проверять, что `ActivityFirst=да` в `$commentDetails`
     - Валидировать наличие необходимых данных (`fileIds`, `dealIds`, `taskId`)
     - Использовать `outgoingWebhookGetSyncServices()` для получения сервисов
     - Перестроить `commentDetails` для консистентности (вызвать `buildDetails()` или `buildDetailsFromChat()`)
     - Вызывать `processActivityFirst()` из `CommentDetailsService`
     - Маркировать событие как обработанное через `TaskDetailsService->markActivityFirstProcessed()`
     - Логировать результат и метрики
     - Применять rate limiting для защиты от перегрузки

4. **Интегрировать синхронную обработку в index.php**
   - После построения `commentDetails` (строка 188 или 212)
   - Проверить, что `$commentWritten === true` и `ActivityFirst=да`
   - Проверить конфигурационный флаг `ACTIVITY_FIRST_SYNC_ENABLED`
   - Отправить ответ Bitrix24 сразу (использовать `fastcgi_finish_request()` или `register_shutdown_function()`)
   - Вызвать `outgoingWebhookProcessActivityFirstSync()` в фоне (после отправки ответа)
   - Обработать ошибки без прерывания основного потока
   - Если `fastcgi_finish_request()` недоступен, использовать `register_shutdown_function()` как fallback

5. **Добавить механизм предотвращения дублирования обработки**
   - В `TaskDetailsService` добавить метод `markActivityFirstProcessed(string $requestId, string $taskId): void`
   - Метод должен сохранять информацию о обработанном событии в `state/activity-first-processed/`
   - В `QueueRunner` добавить проверку перед асинхронной обработкой
   - Если событие уже обработано синхронно, пропускать асинхронную обработку
   - Формат файла состояния: `{requestId}_{taskId}.json` с метаданными

6. **Добавить логирование синхронной обработки и метрики**
   - Логировать начало синхронной обработки в `activity-first.log`
   - Логировать успех/ошибку обработки
   - Различать синхронную и асинхронную обработку в логах (добавить поле `sync: true`)
   - Логировать метрики в `activity-first-metrics.log`:
     - Время выполнения (миллисекунды)
     - Количество успешных/неуспешных обработок
     - Количество обработанных файлов
     - Количество обновленных сделок

7. **Добавить rate limiting для синхронной обработки**
   - Ограничить количество одновременных синхронных обработок (например, максимум 5)
   - Добавить задержку между обработками при превышении лимита
   - Логировать случаи превышения лимита
   - Использовать файловую блокировку для синхронизации

8. **Обновить асинхронную обработку для использования нового метода**
   - В `CommentDetailsService->handleCommentAdd()` использовать новый метод `processActivityFirst()`
   - Добавить проверку, не была ли уже выполнена синхронная обработка
   - Убрать дублирование кода

9. **Добавить обработку ошибок и fallback**
   - Если синхронная обработка не удалась, задание должно остаться в очереди
   - Асинхронная обработка должна выполниться как fallback
   - Проверять, не была ли уже выполнена обработка перед повторной попыткой
   - Логировать ошибки синхронной обработки в `logs/errors/`

## Технические требования

### Производительность:
- Синхронная обработка не должна задерживать ответ Bitrix24 (выполняется в фоне после отправки ответа)
- Использовать `fastcgi_finish_request()` для фоновой обработки, `register_shutdown_function()` как fallback
- Таймаут для REST API вызовов: 30 секунд
- Rate limiting: максимум 5 одновременных синхронных обработок
- Время выполнения синхронной обработки: не более 10 секунд (с учетом всех REST API вызовов)

### Обработка ошибок:
- Ошибки синхронной обработки не должны прерывать основной поток
- Ошибки должны логироваться в `logs/errors/`
- Задание должно остаться в очереди для повторной попытки (fallback)
- При ошибке синхронной обработки не маркировать событие как обработанное
- Асинхронная обработка должна проверить, не была ли уже выполнена обработка, перед повторной попыткой

### Логирование:
- Формат лога в `activity-first.log`:
  ```json
  {
    "loggedAt": "2026-01-23T22:15:00+03:00",
    "requestId": "abc123",
    "taskId": "907",
    "sync": true,
    "dealIds": ["12235"],
    "fileIds": ["35161"],
    "taskAttach": {...},
    "dealUpdates": [...]
  }
  ```
- Поле `sync: true` указывает на синхронную обработку, `sync: false` или отсутствие поля — на асинхронную

### Метрики:
- Формат лога в `activity-first-metrics.log`:
  ```json
  {
    "loggedAt": "2026-01-23T22:15:00+03:00",
    "requestId": "abc123",
    "taskId": "907",
    "sync": true,
    "durationMs": 1250,
    "success": true,
    "filesCount": 1,
    "dealsCount": 1,
    "rateLimitHit": false
  }
  ```

### Конфигурация:
- Переменная окружения `ACTIVITY_FIRST_SYNC_ENABLED` (по умолчанию `true`)
  - `true` — синхронная обработка включена
  - `false` — синхронная обработка отключена, используется только асинхронная
- Переменная окружения `ACTIVITY_FIRST_SYNC_RATE_LIMIT` (по умолчанию `5`)
  - Максимальное количество одновременных синхронных обработок

### Условия для ActivityFirst:
- Условия определяются в `activity/first/conditions.php`
- Проверка выполняется через `TaskDetailsService->evaluateActivityFirst()`
- Условия:
  - Проект ID = `15` (из условий)
  - Есть ссылка на сделку с префиксом `D_`
  - В тексте комментария есть ключевое слово: `"обложка"` (любой регистр)
  - Есть прикрепленные файлы

## API-методы Bitrix24

Используются следующие методы REST API:
- `tasks.task.get` — получение данных задачи
- `tasks.task.files.attach` — прикрепление файлов к задаче
- `crm.deal.update` — обновление файлов в сделке (поле `UF_CRM_1759233362672`)
- `im.dialog.messages.get` — получение сообщений из чата (fallback)

Документация:
- https://context7.com/bitrix24/rest/tasks.task.get
- https://context7.com/bitrix24/rest/tasks.task.files.attach
- https://context7.com/bitrix24/rest/crm.deal.update
- https://context7.com/bitrix24/rest/im.dialog.messages.get

## Критерии приёмки

- [ ] Метод `processActivityFirst()` создан в `CommentDetailsService` с валидацией данных
- [ ] Функция `outgoingWebhookGetSyncServices()` создана в `bootstrap.php`
- [ ] Функция `outgoingWebhookProcessActivityFirstSync()` создана в `bootstrap.php` с rate limiting
- [ ] Методы `markActivityFirstProcessed()` и `isActivityFirstProcessed()` созданы в `TaskDetailsService`
- [ ] Синхронная обработка интегрирована в `index.php` с использованием `fastcgi_finish_request()` или `register_shutdown_function()`
- [ ] События с `ActivityFirst=да` обрабатываются синхронно (в фоне после отправки ответа)
- [ ] Ответ Bitrix24 отправляется без задержки (обработка выполняется в фоне)
- [ ] Логирование синхронной обработки добавлено в `activity-first.log` с полем `sync: true`
- [ ] Метрики логируются в `activity-first-metrics.log`
- [ ] Ошибки синхронной обработки не прерывают основной поток
- [ ] Асинхронная обработка через очередь проверяет, не была ли уже выполнена синхронная обработка
- [ ] Асинхронная обработка через очередь остаётся как fallback
- [ ] Код не дублируется (используется общий метод `processActivityFirst()`)
- [ ] Rate limiting работает корректно (максимум одновременных обработок)
- [ ] Конфигурационный флаг `ACTIVITY_FIRST_SYNC_ENABLED` работает (можно отключить синхронную обработку)
- [ ] Тестирование показало, что обработка выполняется в течение 1-2 секунд после получения события
- [ ] Тестирование показало, что дублирование обработки предотвращено

## Примеры кода

### Пример 1: Метод processActivityFirst в CommentDetailsService

```php
/**
 * Обработка ActivityFirst (синхронная или асинхронная)
 * 
 * @param array $commentDetails Детали комментария с ActivityFirst=да
 * @param string $entityId ID задачи
 * @param callable $restCall Функция для REST API вызовов
 * @return array Результат обработки для логирования
 * @throws InvalidArgumentException При отсутствии необходимых данных
 */
public function processActivityFirst(
    array $commentDetails,
    string $entityId,
    callable $restCall
): array {
    // Валидация входных данных
    if (empty($entityId) || !is_string($entityId)) {
        throw new InvalidArgumentException('Invalid taskId: ' . ($entityId ?? 'null'));
    }

    $fileIds = $commentDetails['fileIds'] ?? [];
    $fileIds = is_array($fileIds) ? array_values(array_unique($fileIds)) : [];
    
    if (empty($fileIds)) {
        throw new InvalidArgumentException('FileIds are required for ActivityFirst processing');
    }

    $dealIds = $this->taskDetails->extractDealIds($commentDetails['crmLinks'] ?? []);
    
    if (empty($dealIds)) {
        throw new InvalidArgumentException('DealIds are required for ActivityFirst processing');
    }

    // Валидация каждого fileId
    foreach ($fileIds as $fileId) {
        if (!is_numeric($fileId) || (int)$fileId <= 0) {
            throw new InvalidArgumentException('Invalid fileId: ' . $fileId);
        }
    }

    // Валидация каждого dealId
    foreach ($dealIds as $dealId) {
        if (empty($dealId) || !is_string($dealId)) {
            throw new InvalidArgumentException('Invalid dealId: ' . ($dealId ?? 'null'));
        }
    }

    $taskAttach = ['attached' => [], 'errors' => []];
    if (!empty($fileIds)) {
        $taskAttach = $this->taskFiles->attachFiles($entityId, $fileIds, $restCall);
    }

    $dealUpdates = [];
    $fileDataList = [];
    foreach ($fileIds as $fileId) {
        $fileData = $this->dealFiles->buildDealFileData($fileId, $restCall);
        if ($fileData !== null) {
            $fileDataList[] = ['fileData' => $fileData];
        }
    }

    foreach ($dealIds as $dealId) {
        $dealUpdates[] = array_merge(
            ['dealId' => $dealId],
            $this->dealFiles->updateDealFiles($dealId, 'UF_CRM_1759233362672', $fileDataList, $restCall)
        );
    }

    return [
        'dealIds' => $dealIds,
        'fileIds' => $fileIds,
        'taskAttach' => $taskAttach,
        'dealUpdates' => $dealUpdates,
    ];
}
```

### Пример 2: Функция получения сервисов для синхронной обработки

```php
/**
 * Получение сервисов для синхронной обработки ActivityFirst
 * 
 * @return array Массив сервисов
 */
function outgoingWebhookGetSyncServices(): array
{
    static $services = null;
    if ($services !== null) {
        return $services;
    }

    require_once __DIR__ . '/../app/crest.php';
    require_once __DIR__ . '/../app/Services/Bitrix24Client.php';
    require_once __DIR__ . '/services/bootstrap.php';

    $config = new ConfigService();
    $filesystem = new FilesystemService();
    $request = new RequestService($config);
    $formatter = new LogValueFormatter();
    $errors = new ErrorService($filesystem, $request);
    $rest = new RestService(new Bitrix24Client(), $config, $errors);
    
    $taskDetails = new TaskDetailsService($filesystem, $request, $formatter);
    $taskFiles = new TaskFilesService();
    $dealFiles = new DealFileService($filesystem, $request, $taskFiles);
    $identity = new EntityIdentityService($request);
    
    $commentDetailsService = new CommentDetailsService(
        $rest,
        $errors,
        $taskDetails,
        $taskFiles,
        $dealFiles,
        $identity,
        $request,
        $formatter,
        $filesystem,
        $config
    );

    $services = [
        'config' => $config,
        'filesystem' => $filesystem,
        'request' => $request,
        'formatter' => $formatter,
        'errors' => $errors,
        'rest' => $rest,
        'taskDetails' => $taskDetails,
        'taskFiles' => $taskFiles,
        'dealFiles' => $dealFiles,
        'identity' => $identity,
        'commentDetailsService' => $commentDetailsService,
    ];

    return $services;
}
```

### Пример 3: Функция синхронной обработки в bootstrap.php

```php
/**
 * Синхронная обработка ActivityFirst
 * 
 * Выполняется сразу после получения webhook-события (в фоне после отправки ответа)
 * 
 * @param array $commentDetails Детали комментария
 * @param string $taskId ID задачи
 * @param string $requestId ID запроса
 * @return bool Успешность обработки
 */
function outgoingWebhookProcessActivityFirstSync(
    array $commentDetails,
    string $taskId,
    string $requestId
): bool {
    // Проверка конфигурационного флага
    $enabled = outgoingWebhookGetSetting('ACTIVITY_FIRST_SYNC_ENABLED', 'true');
    if ($enabled !== 'true' && $enabled !== '1') {
        return false;
    }

    if (empty($commentDetails['activityFirst'])) {
        return false;
    }

    // Валидация данных
    $fileIds = $commentDetails['fileIds'] ?? [];
    $dealIds = $commentDetails['crmLinks'] ?? [];
    if (empty($fileIds) || empty($dealIds)) {
        outgoingWebhookLogError('ActivityFirst sync: missing required data', [
            'requestId' => $requestId,
            'taskId' => $taskId,
            'fileIds' => $fileIds,
            'dealIds' => $dealIds,
        ]);
        return false;
    }

    // Rate limiting
    $rateLimit = (int) outgoingWebhookGetSetting('ACTIVITY_FIRST_SYNC_RATE_LIMIT', '5');
    $lockFile = __DIR__ . '/state/activity-first-sync.lock';
    $lockHandle = fopen($lockFile, 'c+');
    if (!$lockHandle) {
        outgoingWebhookLogError('ActivityFirst sync: cannot create lock file', [
            'requestId' => $requestId,
            'taskId' => $taskId,
        ]);
        return false;
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        outgoingWebhookLogError('ActivityFirst sync: rate limit exceeded', [
            'requestId' => $requestId,
            'taskId' => $taskId,
        ]);
        return false;
    }

    $startTime = microtime(true);

    try {
        $services = outgoingWebhookGetSyncServices();
        $restCall = fn(string $method, array $params = []) => $services['rest']->call($method, $params);
        
        // Перестроить commentDetails для консистентности (если нужно)
        // Здесь можно добавить перестроение, если требуется
        
        $result = $services['commentDetailsService']->processActivityFirst(
            $commentDetails,
            $taskId,
            $restCall
        );

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Маркировать как обработанное
        $services['taskDetails']->markActivityFirstProcessed($requestId, $taskId);

        // Логирование результата
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

        // Логирование метрик
        $metricsPath = dirname(__DIR__, 2) . '/logs/activity-first-metrics.log';
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
        $services['filesystem']->appendLine($metricsPath, json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        return true;
    } catch (Throwable $e) {
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        
        outgoingWebhookLogError('ActivityFirst sync processing failed', [
            'requestId' => $requestId,
            'taskId' => $taskId,
            'message' => $e->getMessage(),
            'durationMs' => $durationMs,
        ]);

        // Логирование метрик ошибки
        $services = outgoingWebhookGetSyncServices();
        $metricsPath = dirname(__DIR__, 2) . '/logs/activity-first-metrics.log';
        $metrics = [
            'loggedAt' => $services['request']->now(),
            'requestId' => $requestId,
            'taskId' => $taskId,
            'sync' => true,
            'durationMs' => $durationMs,
            'success' => false,
            'error' => $e->getMessage(),
        ];
        $services['filesystem']->appendLine($metricsPath, json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        return false;
    }
}
```

### Пример 4: Интеграция в index.php

```php
// После строки 188 или 212 (после outgoingWebhookWriteCommentDetailsRu)

if ($commentWritten && is_array($details) && !empty($details['activityFirst'])) {
    // Отправляем ответ Bitrix24 сразу
    outgoingWebhookJsonResponse(200, ['status' => 'ok']);
    
    // Выполняем синхронную обработку в фоне
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        // Обработка после отправки ответа
        outgoingWebhookProcessActivityFirstSync($details, $entityId, $requestId);
    } else {
        // Fallback: используем register_shutdown_function
        register_shutdown_function(function() use ($details, $entityId, $requestId) {
            outgoingWebhookProcessActivityFirstSync($details, $entityId, $requestId);
        });
    }
    exit;
}
```

### Пример 5: Метод маркировки обработанных событий в TaskDetailsService

```php
/**
 * Маркировка события как синхронно обработанного
 * 
 * @param string $requestId ID запроса
 * @param string $taskId ID задачи
 */
public function markActivityFirstProcessed(string $requestId, string $taskId): void
{
    $stateDir = $this->basePath . '/state/activity-first-processed';
    $this->filesystem->ensureDir($stateDir);
    
    $stateFile = $stateDir . '/' . $requestId . '_' . $taskId . '.json';
    $state = [
        'requestId' => $requestId,
        'taskId' => $taskId,
        'processedAt' => $this->request->now(),
        'sync' => true,
    ];
    
    $this->filesystem->writeJson($stateFile, $state);
}

/**
 * Проверка, было ли событие уже обработано синхронно
 * 
 * @param string $requestId ID запроса
 * @param string $taskId ID задачи
 * @return bool
 */
public function isActivityFirstProcessed(string $requestId, string $taskId): bool
{
    $stateFile = $this->basePath . '/state/activity-first-processed/' . $requestId . '_' . $taskId . '.json';
    return file_exists($stateFile);
}
```

### Пример 6: Проверка в QueueRunner перед асинхронной обработкой

```php
// В методе run() перед обработкой ONTASKCOMMENTADD

if ($eventType === 'ONTASKCOMMENTADD') {
    $taskDetails = $this->taskDetails;
    if ($taskDetails->isActivityFirstProcessed($job['requestId'] ?? 'unknown', $entityId)) {
        // Событие уже обработано синхронно, пропускаем
        $this->jobState->markDone($processingJob);
        $this->steps->finished([
            'requestId' => $job['requestId'] ?? 'unknown',
            'eventType' => $eventType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'attempt' => $job['attempt'] ?? 0,
            'jobFile' => $processingJob->getName(),
            'status' => 'skipped',
            'reason' => 'already_processed_sync',
        ]);
        continue;
    }
}
```

## Тестирование

1. **Тест синхронной обработки:**
   - Создать комментарий к задаче с условиями ActivityFirst
   - Проверить, что файлы прикреплены к задаче в течение 2 секунд
   - Проверить, что файлы обновлены в сделке в течение 2 секунд
   - Проверить запись в `activity-first.log` с `sync: true`

2. **Тест обработки ошибок:**
   - Симулировать ошибку REST API (недоступность Bitrix24)
   - Проверить, что ответ Bitrix24 отправлен успешно
   - Проверить, что ошибка залогирована
   - Проверить, что задание осталось в очереди для повторной попытки

3. **Тест производительности:**
   - Измерить время обработки синхронной операции
   - Убедиться, что ответ Bitrix24 отправлен в течение 1 секунды
   - Убедиться, что обработка завершена в течение 5 секунд

4. **Тест fallback:**
   - Отключить синхронную обработку через `ACTIVITY_FIRST_SYNC_ENABLED=false`
   - Проверить, что задание обработалось через очередь
   - Проверить запись в `activity-first.log` с `sync: false` (или без поля)

5. **Тест предотвращения дублирования:**
   - Выполнить синхронную обработку события
   - Проверить, что файл состояния создан в `state/activity-first-processed/`
   - Проверить, что асинхронная обработка пропустила событие (статус `skipped`)

6. **Тест rate limiting:**
   - Отправить более 5 событий одновременно
   - Проверить, что часть событий обработана, часть получила ошибку rate limit
   - Проверить логирование превышения лимита

7. **Тест валидации данных:**
   - Отправить событие с `ActivityFirst=да`, но без `fileIds` или `dealIds`
   - Проверить, что синхронная обработка вернула `false`
   - Проверить логирование ошибки валидации

8. **Тест конфигурационного флага:**
   - Установить `ACTIVITY_FIRST_SYNC_ENABLED=false`
   - Проверить, что синхронная обработка не выполняется
   - Проверить, что обработка происходит только через очередь

## История правок

- 2026-01-23 22:15 (UTC+3, Брест): Создана задача
- 2026-01-23 22:30 (UTC+3, Брест): Добавлены улучшения на основе вопросов:
  - Добавлен механизм предотвращения дублирования обработки
  - Добавлена функция получения сервисов для синхронной обработки
  - Добавлена валидация данных перед обработкой
  - Добавлен конфигурационный флаг `ACTIVITY_FIRST_SYNC_ENABLED`
  - Добавлен rate limiting для защиты от перегрузки
  - Добавлено логирование метрик в отдельный файл
  - Добавлена поддержка `register_shutdown_function()` как fallback
  - Добавлена перестройка `commentDetails` для консистентности
  - Улучшена обработка ошибок и fallback механизм
