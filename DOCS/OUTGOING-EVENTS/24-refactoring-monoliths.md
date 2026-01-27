# Рефакторинг монолитных частей модуля

**Дата создания:** 2026-01-27 (UTC+3, Брест)  
**Версия:** 1.0  
**Статус:** План рефакторинга

## Назначение

Документ описывает план рефакторинга монолитных частей модуля `outgoing-webhook` для улучшения поддерживаемости, тестируемости и масштабируемости кода.

---

## Проблемные области

### 1. `index.php` — монолитный endpoint (283 строки)

**Файл:** `outgoing-webhook/index.php`

**Проблемы:**
- Смешение ответственности: валидация, аутентификация, логирование, бизнес-логика в одном файле
- Глубокая вложенность: до 5-6 уровней в блоке обработки комментариев (строки 155-281)
- Дублирование кода: повторяющиеся вызовы `$client->call('tasks.task.get')` (строки 129, 171, 193, 235)
- Сложно тестировать: много зависимостей и условий
- Нарушение Single Responsibility Principle

**Текущая структура:**
```php
// Строки 6-49: Валидация и аутентификация
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { ... }
if (!hash_equals($expectedToken, $authInfo['token'])) { ... }

// Строки 51-95: Извлечение данных и логирование
$eventType = outgoingWebhookNormalizeEventType(...);
outgoingWebhookWriteJson($rawPath, $raw);

// Строки 97-122: Создание задачи в очереди
$queueItem = [...];
outgoingWebhookWriteJson($queuePath, $queueItem);

// Строки 124-153: Специальная обработка задач (ONTASK)
if ($entityId !== null && str_starts_with($eventType, 'ONTASK')) { ... }

// Строки 155-281: Специальная обработка комментариев (ONTASKCOMMENTADD)
if ($entityId !== null && $eventType === 'ONTASKCOMMENTADD') {
    // 127 строк вложенной логики!
}
```

---

### 2. `bootstrap.php` — файл с функциями-обертками (619 строк)

**Файл:** `outgoing-webhook/bootstrap.php`

**Проблемы:**
- 64 функции-обертки без добавленной логики
- Service Locator паттерн вместо Dependency Injection
- Сложно тестировать: глобальные функции сложно мокировать
- Дублирование логики создания сервисов в 3 местах:
  - `bootstrap.php` (строки 10-65): `outgoingWebhookService()`
  - `tools/process-queue.php` (строки 12-70): `outgoingWebhookGetServices()`
  - `bootstrap.php` (строки 430-481): `outgoingWebhookGetSyncServices()`

**Пример избыточности:**
```php
function outgoingWebhookNow(): string
{
    return outgoingWebhookService('request')->now();
}

function outgoingWebhookGetEnv(string $key, ?string $default = null): ?string
{
    return outgoingWebhookService('config')->getEnv($key, $default);
}

// ... еще 62 такие функции
```

---

### 3. `CommentDetailsService.php` — большой класс (630 строк)

**Файл:** `outgoing-webhook/services/Task/CommentDetailsService.php`

**Проблемы:**
- 16 методов с множественной ответственностью
- 10 зависимостей в конструкторе
- Нарушение Single Responsibility Principle:
  - Получение деталей комментариев (`fetchDetails`, `fetchChatMessageDetails`)
  - Построение структур данных (`buildDetails`, `buildDetailsFromChat`, `buildFallback`)
  - Форматирование (`formatDetailsRu`)
  - Запись в файлы (`writeDetailsRu`, `writeEnriched`)
  - Обработка ActivityFirst (`processActivityFirst`)
  - Работа с чатами (`extractChatId`, `findChatMessage`)

**Методы класса:**
- `handleCommentAdd()` — обработка добавления комментария
- `buildDetails()` — построение деталей комментария
- `buildFallback()` — построение fallback-данных
- `resolveKind()` — определение типа комментария
- `formatDetailsRu()` — форматирование для логов
- `writeDetailsRu()` — запись деталей в файл
- `findCommentItem()` — поиск комментария в payload
- `fetchDetails()` — получение деталей через REST API
- `extractChatId()` — извлечение ID чата
- `findChatMessage()` — поиск сообщения в payload
- `fetchChatMessageDetails()` — получение деталей через чат API
- `buildDetailsFromChat()` — построение деталей из чата
- `writeEnriched()` — запись обогащенных данных
- `shouldWriteEnriched()` — проверка необходимости записи
- `processActivityFirst()` — обработка ActivityFirst

---

## План рефакторинга

### Этап 1: Рефакторинг `index.php`

**Цель:** Разделить монолитный endpoint на отдельные компоненты с четкой ответственностью.

#### 1.1. Создание RequestValidator

**Файл:** `outgoing-webhook/services/Http/RequestValidator.php`

**Ответственность:**
- Валидация HTTP-метода
- Валидация размера payload
- Валидация структуры payload

**Пример:**
```php
<?php
declare(strict_types=1);

class RequestValidator
{
    private int $maxBytes;

    public function __construct(int $maxBytes = OUTGOING_WEBHOOK_MAX_BYTES)
    {
        $this->maxBytes = $maxBytes;
    }

    public function validateMethod(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new InvalidRequestException('method_not_allowed', 405);
        }
    }

    public function validateContentLength(): void
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $this->maxBytes) {
            throw new InvalidRequestException('payload_too_large', 413);
        }
    }

    public function validatePayload(array $payload): void
    {
        if (empty($payload)) {
            throw new InvalidRequestException('invalid_payload', 400);
        }
    }
}
```

#### 1.2. Создание AuthMiddleware

**Файл:** `outgoing-webhook/services/Security/AuthMiddleware.php`

**Ответственность:**
- Извлечение токена из payload
- Валидация токена
- Проверка IP-адреса (если настроено)

**Пример:**
```php
<?php
declare(strict_types=1);

class AuthMiddleware
{
    private AccessService $access;
    private ConfigService $config;

    public function __construct(AccessService $access, ConfigService $config)
    {
        $this->access = $access;
        $this->config = $config;
    }

    public function authenticate(array $payload, string $clientIp): void
    {
        $expectedToken = $this->config->get('OUTGOING_WEBHOOK_TOKEN');
        if ($expectedToken === null) {
            throw new AuthenticationException('server_not_configured', 500);
        }

        $authInfo = $this->access->extractAuthInfo($payload);
        if (!hash_equals($expectedToken, $authInfo['token'])) {
            throw new AuthenticationException('invalid_token', 403);
        }

        $allowedIps = $this->access->getAllowedIps();
        if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
            throw new AuthenticationException('ip_not_allowed', 403);
        }
    }
}
```

#### 1.3. Создание EventProcessor

**Файл:** `outgoing-webhook/services/Event/EventProcessor.php`

**Ответственность:**
- Извлечение данных события
- Создание задачи в очереди
- Логирование события

**Пример:**
```php
<?php
declare(strict_types=1);

class EventProcessor
{
    private RequestService $request;
    private EntityIdentityService $identity;
    private FilesystemService $filesystem;
    private ErrorService $errors;

    public function __construct(
        RequestService $request,
        EntityIdentityService $identity,
        FilesystemService $filesystem,
        ErrorService $errors
    ) {
        $this->request = $request;
        $this->identity = $identity;
        $this->filesystem = $filesystem;
        $this->errors = $errors;
    }

    public function process(array $payload, string $requestId, string $clientIp, array $authInfo): array
    {
        $eventType = $this->request->normalizeEventType(
            (string) ($payload['event'] ?? ($payload['eventName'] ?? ''))
        );
        
        $entityId = $this->identity->normalizeEntityId(
            $this->identity->extractEntityId($payload)
        );
        
        if (str_starts_with($eventType, 'ONTASKCOMMENT') && $entityId === null) {
            $entityId = $this->identity->normalizeEntityId(
                $this->identity->extractTaskId($payload)
            );
        }
        
        $entityType = $this->identity->resolveEntityType($eventType);
        
        // Логирование
        $this->logEvent($eventType, $entityType, $entityId, $requestId, $clientIp, $authInfo, $payload);
        
        // Создание задачи в очереди
        $queueItem = $this->createQueueItem(
            $requestId,
            $eventType,
            $entityType,
            $entityId,
            $authInfo,
            $payload
        );
        
        return [
            'eventType' => $eventType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'queueItem' => $queueItem,
        ];
    }

    private function logEvent(
        string $eventType,
        string $entityType,
        ?string $entityId,
        string $requestId,
        string $clientIp,
        array $authInfo,
        array $payload
    ): void {
        $eventDir = dirname(__DIR__, 2) . '/logs/' . $eventType;
        $this->filesystem->ensureDir($eventDir);

        $maskedPayload = (new LogValueFormatter())->maskPayload($payload);
        $raw = [
            'requestId' => $requestId,
            'eventType' => $eventType,
            'receivedAt' => $this->request->now(),
            'ip' => $clientIp,
            'tokenSource' => $authInfo['source'],
            'eventHandlerId' => (string) ($payload['event_handler_id'] ?? 'unknown'),
            'memberId' => (string) ($payload['auth']['member_id'] ?? 'unknown'),
            'payload' => $maskedPayload,
        ];

        $rawPath = $eventDir . '/raw.json';
        if (!$this->filesystem->writeJson($rawPath, $raw)) {
            $this->errors->log('Failed to write raw.json', ['path' => $rawPath]);
        }

        $eventLogPath = $eventDir . '/event.log';
        $eventLogLine = sprintf(
            '%s | IP=%s | requestId=%s | event=%s | entityType=%s | entityId=%s | eventHandlerId=%s | memberId=%s | tokenSource=%s',
            $this->request->now(),
            $clientIp,
            $requestId,
            $eventType,
            $entityType,
            $entityId ?? 'unknown',
            $payload['event_handler_id'] ?? 'unknown',
            $payload['auth']['member_id'] ?? 'unknown',
            $authInfo['source']
        );
        if (!$this->filesystem->appendLine($eventLogPath, $eventLogLine)) {
            $this->errors->log('Failed to write event.log', ['path' => $eventLogPath]);
        }
    }

    private function createQueueItem(
        string $requestId,
        string $eventType,
        string $entityType,
        ?string $entityId,
        array $authInfo,
        array $payload
    ): array {
        $eventDir = dirname(__DIR__, 2) . '/logs/' . $eventType;
        $rawPath = $eventDir . '/raw.json';

        $maskedPayload = (new LogValueFormatter())->maskPayload($payload);
        $queueItem = [
            'requestId' => $requestId,
            'eventType' => $eventType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'rawPath' => $rawPath,
            'createdAt' => $this->request->now(),
            'attempt' => 0,
            'priority' => 'normal',
            'source' => 'outgoing-webhook',
            'tokenSource' => $authInfo['source'],
            'eventHandlerId' => (string) ($payload['event_handler_id'] ?? 'unknown'),
            'memberId' => (string) ($payload['auth']['member_id'] ?? 'unknown'),
            'payload' => $maskedPayload,
        ];

        $queueName = sprintf(
            '%s_%s_%s.json',
            date('Ymd_His'),
            $eventType,
            $entityId ?? 'unknown'
        );
        $queuePath = dirname(__DIR__, 2) . '/queue/pending/' . $queueName;
        
        if (!$this->filesystem->writeJson($queuePath, $queueItem)) {
            $this->errors->log('Failed to enqueue item', ['path' => $queuePath]);
        }

        return $queueItem;
    }
}
```

#### 1.4. Создание TaskEventHandler

**Файл:** `outgoing-webhook/services/Event/Handlers/TaskEventHandler.php`

**Ответственность:**
- Обработка событий задач (ONTASKADD, ONTASKUPDATE)
- Получение деталей задачи через REST API
- Запись деталей задачи

**Пример:**
```php
<?php
declare(strict_types=1);

class TaskEventHandler
{
    private RestService $rest;
    private TaskDetailsService $taskDetails;
    private ErrorService $errors;

    public function __construct(
        RestService $rest,
        TaskDetailsService $taskDetails,
        ErrorService $errors
    ) {
        $this->rest = $rest;
        $this->taskDetails = $taskDetails;
        $this->errors = $errors;
    }

    public function handle(string $eventType, ?string $entityId, string $requestId): void
    {
        if ($entityId === null || !str_starts_with($eventType, 'ONTASK')) {
            return;
        }

        try {
            $result = $this->rest->call('tasks.task.get', ['id' => $entityId]);
            
            if (!empty($result['error'])) {
                $this->errors->log('Task details REST error', [
                    'requestId' => $requestId,
                    'taskId' => $entityId,
                    'error' => $result['error'],
                ]);
                return;
            }

            $taskPayload = $result['result'] ?? $result;
            if (!is_array($taskPayload)) {
                return;
            }

            $taskData = $taskPayload['task'] ?? $taskPayload;
            if (!is_array($taskData)) {
                return;
            }

            $details = $this->taskDetails->buildDetails(
                $taskData,
                $eventType,
                $requestId,
                $entityId
            );
            
            $this->taskDetails->writeDetailsRu($eventType, $details);
        } catch (Throwable $e) {
            $this->errors->log('Task details exception', [
                'requestId' => $requestId,
                'taskId' => $entityId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
```

#### 1.5. Создание CommentEventHandler

**Файл:** `outgoing-webhook/services/Event/Handlers/CommentEventHandler.php`

**Ответственность:**
- Обработка событий комментариев (ONTASKCOMMENTADD)
- Получение деталей комментария
- Обработка ActivityFirst (синхронная)

**Пример:**
```php
<?php
declare(strict_types=1);

class CommentEventHandler
{
    private CommentDetailsService $commentDetails;
    private ErrorService $errors;

    public function __construct(
        CommentDetailsService $commentDetails,
        ErrorService $errors
    ) {
        $this->commentDetails = $commentDetails;
        $this->errors = $errors;
    }

    public function handle(
        string $eventType,
        array $payload,
        ?string $entityId,
        string $requestId,
        string $rawPath
    ): bool {
        if ($entityId === null || $eventType !== 'ONTASKCOMMENTADD') {
            return false;
        }

        $commentId = $this->commentDetails->extractCommentId($payload);
        if ($commentId === null) {
            return false;
        }

        try {
            $result = $this->commentDetails->processComment(
                $eventType,
                $payload,
                $entityId,
                $commentId,
                $requestId,
                $rawPath
            );

            // Синхронная обработка ActivityFirst
            if ($result['activityFirst'] ?? false) {
                return true; // Требуется синхронная обработка
            }

            return false;
        } catch (Throwable $e) {
            $this->errors->log('Comment details exception', [
                'requestId' => $requestId,
                'taskId' => $entityId,
                'commentId' => $commentId,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
```

#### 1.6. Рефакторинг `index.php`

**Новая структура:**
```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/services/bootstrap.php';

use OutgoingWebhook\Services\Http\RequestValidator;
use OutgoingWebhook\Services\Security\AuthMiddleware;
use OutgoingWebhook\Services\Event\EventProcessor;
use OutgoingWebhook\Services\Event\Handlers\TaskEventHandler;
use OutgoingWebhook\Services\Event\Handlers\CommentEventHandler;

try {
    // Инициализация сервисов
    $container = new ServiceContainer();
    
    $validator = new RequestValidator();
    $auth = new AuthMiddleware(
        $container->get('access'),
        $container->get('config')
    );
    $processor = new EventProcessor(
        $container->get('request'),
        $container->get('identity'),
        $container->get('filesystem'),
        $container->get('errors')
    );
    $taskHandler = new TaskEventHandler(
        $container->get('rest'),
        $container->get('taskDetails'),
        $container->get('errors')
    );
    $commentHandler = new CommentEventHandler(
        $container->get('commentDetails'),
        $container->get('errors')
    );

    // Валидация запроса
    $validator->validateMethod();
    $validator->validateContentLength();
    
    $payload = $container->get('request')->readPayload();
    $validator->validatePayload($payload);

    // Аутентификация
    $requestId = $container->get('request')->generateRequestId();
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $auth->authenticate($payload, $clientIp);
    $authInfo = $container->get('access')->extractAuthInfo($payload);

    // Обработка события
    $eventData = $processor->process($payload, $requestId, $clientIp, $authInfo);

    // Специальная обработка задач
    $taskHandler->handle(
        $eventData['eventType'],
        $eventData['entityId'],
        $requestId
    );

    // Специальная обработка комментариев
    $rawPath = dirname(__DIR__) . '/logs/' . $eventData['eventType'] . '/raw.json';
    $needsSync = $commentHandler->handle(
        $eventData['eventType'],
        $payload,
        $eventData['entityId'],
        $requestId,
        $rawPath
    );

    // Синхронная обработка ActivityFirst (если требуется)
    if ($needsSync) {
        outgoingWebhookJsonResponse(200, ['status' => 'ok']);
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            // Обработка после отправки ответа
            $container->get('activityFirst')->processSync($eventData, $requestId);
        } else {
            register_shutdown_function(function() use ($container, $eventData, $requestId) {
                $container->get('activityFirst')->processSync($eventData, $requestId);
            });
        }
        exit;
    }

    outgoingWebhookJsonResponse(200, ['status' => 'ok']);
} catch (InvalidRequestException $e) {
    outgoingWebhookJsonResponse($e->getCode(), ['error' => $e->getMessage()]);
} catch (AuthenticationException $e) {
    outgoingWebhookJsonResponse($e->getCode(), ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    outgoingWebhookLogError('Unexpected error', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    outgoingWebhookJsonResponse(500, ['error' => 'internal_server_error']);
}
```

---

### Этап 2: Рефакторинг `bootstrap.php`

**Цель:** Убрать избыточные функции-обертки и создать единый Service Container.

#### 2.1. Создание ServiceContainer

**Файл:** `outgoing-webhook/services/Container/ServiceContainer.php`

**Ответственность:**
- Единая точка создания и управления сервисами
- Dependency Injection вместо Service Locator
- Устранение дублирования логики создания сервисов

**Пример:**
```php
<?php
declare(strict_types=1);

class ServiceContainer
{
    private array $services = [];
    private array $factories = [];

    public function __construct()
    {
        $this->registerFactories();
    }

    private function registerFactories(): void
    {
        $this->factories['config'] = fn() => new ConfigService();
        $this->factories['filesystem'] = fn() => new FilesystemService();
        $this->factories['request'] = fn() => new RequestService($this->get('config'));
        $this->factories['access'] = fn() => new AccessService($this->get('config'));
        $this->factories['formatter'] = fn() => new LogValueFormatter();
        $this->factories['errors'] = fn() => new ErrorService(
            $this->get('filesystem'),
            $this->get('request')
        );
        $this->factories['identity'] = fn() => new EntityIdentityService($this->get('request'));
        $this->factories['taskDetails'] = fn() => new TaskDetailsService(
            $this->get('filesystem'),
            $this->get('request'),
            $this->get('formatter')
        );
        $this->factories['taskFiles'] = fn() => new TaskFilesService();
        $this->factories['dealFiles'] = fn() => new DealFileService(
            $this->get('filesystem'),
            $this->get('request'),
            $this->get('taskFiles')
        );
        $this->factories['rest'] = fn() => new RestService(
            new Bitrix24Client(),
            $this->get('config'),
            $this->get('errors')
        );
        $this->factories['commentDetails'] = fn() => new CommentDetailsService(
            $this->get('rest'),
            $this->get('errors'),
            $this->get('taskDetails'),
            $this->get('taskFiles'),
            $this->get('dealFiles'),
            $this->get('identity'),
            $this->get('request'),
            $this->get('formatter'),
            $this->get('filesystem'),
            $this->get('config')
        );
        // ... остальные сервисы
    }

    public function get(string $key)
    {
        if (isset($this->services[$key])) {
            return $this->services[$key];
        }

        if (!isset($this->factories[$key])) {
            throw new InvalidArgumentException("Unknown service: {$key}");
        }

        $this->services[$key] = $this->factories[$key]();
        return $this->services[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->factories[$key]);
    }
}
```

#### 2.2. Минимизация функций-оберток

**Стратегия:**
- Оставить только критически важные функции для обратной совместимости
- Остальные функции помечать как `@deprecated`
- Постепенно мигрировать код на использование ServiceContainer

**Пример:**
```php
<?php
declare(strict_types=1);

// Глобальный контейнер для обратной совместимости
$GLOBALS['outgoingWebhookContainer'] = null;

function outgoingWebhookContainer(): ServiceContainer
{
    if ($GLOBALS['outgoingWebhookContainer'] === null) {
        $GLOBALS['outgoingWebhookContainer'] = new ServiceContainer();
    }
    return $GLOBALS['outgoingWebhookContainer'];
}

// Критически важные функции (оставляем)
function outgoingWebhookNow(): string
{
    return outgoingWebhookContainer()->get('request')->now();
}

function outgoingWebhookLogError(string $message, array $context = []): void
{
    outgoingWebhookContainer()->get('errors')->log($message, $context);
}

// Остальные функции помечаем как deprecated
/**
 * @deprecated Use ServiceContainer::get('config')->get($key, $default) instead
 */
function outgoingWebhookGetSetting(string $key, ?string $default = null): ?string
{
    return outgoingWebhookContainer()->get('config')->get($key, $default);
}
```

---

### Этап 3: Рефакторинг `CommentDetailsService.php`

**Цель:** Разделить большой класс на несколько специализированных классов.

#### 3.1. Создание CommentFetcher

**Файл:** `outgoing-webhook/services/Task/Comment/CommentFetcher.php`

**Ответственность:**
- Получение деталей комментария через REST API
- Получение деталей комментария через чат API
- Fallback-логика

**Пример:**
```php
<?php
declare(strict_types=1);

class CommentFetcher
{
    private RestService $rest;
    private EntityIdentityService $identity;
    private TaskDetailsService $taskDetails;
    private ErrorService $errors;

    public function __construct(
        RestService $rest,
        EntityIdentityService $identity,
        TaskDetailsService $taskDetails,
        ErrorService $errors
    ) {
        $this->rest = $rest;
        $this->identity = $identity;
        $this->taskDetails = $taskDetails;
        $this->errors = $errors;
    }

    public function fetch(string $taskId, string $commentId, ?string $messageId, ?array $taskData): array
    {
        $restCall = fn(string $method, array $params = []) => $this->rest->call($method, $params);
        
        // Попытка получить через task.commentitem.get
        $fetch = $this->fetchFromTask($restCall, $taskId, $commentId);
        if (is_array($fetch['data'])) {
            return $fetch;
        }

        // Fallback: получение через чат
        if ($messageId !== null && is_array($taskData)) {
            $taskData = $this->taskDetails->ensureCrmLinks($taskData, $taskId, $restCall);
            $chatId = $this->extractChatId($taskData);
            if ($chatId !== null) {
                $chatFetch = $this->fetchFromChat($restCall, $chatId, $messageId);
                if (is_array($chatFetch['data'])) {
                    return $chatFetch;
                }
            }
        }

        return ['data' => null, 'errors' => $fetch['errors'] ?? []];
    }

    private function fetchFromTask(callable $restCall, string $taskId, string $commentId): array
    {
        // Логика получения через task.commentitem.get
        // ...
    }

    private function fetchFromChat(callable $restCall, string $chatId, string $messageId): array
    {
        // Логика получения через im.dialog.messages.get
        // ...
    }

    private function extractChatId(array $taskData): ?string
    {
        // Логика извлечения chatId
        // ...
    }
}
```

#### 3.2. Создание CommentBuilder

**Файл:** `outgoing-webhook/services/Task/Comment/CommentBuilder.php`

**Ответственность:**
- Построение структуры деталей комментария
- Построение fallback-данных
- Определение типа комментария

**Пример:**
```php
<?php
declare(strict_types=1);

class CommentBuilder
{
    private EntityIdentityService $identity;
    private RequestService $request;

    public function __construct(
        EntityIdentityService $identity,
        RequestService $request
    ) {
        $this->identity = $identity;
        $this->request = $request;
    }

    public function build(
        array $commentData,
        string $eventType,
        ?string $requestId,
        string $taskId,
        string $commentId,
        string $sourceMethod,
        ?array $taskData = null
    ): array {
        // Логика построения деталей комментария
        // ...
    }

    public function buildFromChat(
        array $messageData,
        string $eventType,
        ?string $requestId,
        string $taskId,
        string $commentId,
        string $sourceMethod,
        ?array $taskData = null
    ): array {
        // Логика построения деталей из чата
        // ...
    }

    public function buildFallback(
        string $eventType,
        ?string $requestId,
        string $taskId,
        string $commentId
    ): array {
        // Логика построения fallback-данных
        // ...
    }

    public function resolveKind($authorId): string
    {
        // Логика определения типа комментария
        // ...
    }
}
```

#### 3.3. Создание CommentFormatter

**Файл:** `outgoing-webhook/services/Task/Comment/CommentFormatter.php`

**Ответственность:**
- Форматирование деталей комментария для логов
- Форматирование для записи в файлы

**Пример:**
```php
<?php
declare(strict_types=1);

class CommentFormatter
{
    private LogValueFormatter $formatter;

    public function __construct(LogValueFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    public function formatDetailsRu(array $details): string
    {
        // Логика форматирования для логов
        // ...
    }
}
```

#### 3.4. Создание CommentWriter

**Файл:** `outgoing-webhook/services/Task/Comment/CommentWriter.php`

**Ответственность:**
- Запись деталей комментария в файлы
- Запись обогащенных данных
- Проверка необходимости записи

**Пример:**
```php
<?php
declare(strict_types=1);

class CommentWriter
{
    private FilesystemService $filesystem;
    private ConfigService $config;
    private string $basePath;

    public function __construct(
        FilesystemService $filesystem,
        ConfigService $config
    ) {
        $this->filesystem = $filesystem;
        $this->config = $config;
        $this->basePath = dirname(__DIR__, 3);
    }

    public function writeDetailsRu(string $eventType, array $details): void
    {
        // Логика записи деталей в файл
        // ...
    }

    public function writeEnriched(
        string $eventType,
        string $entityId,
        array $taskData,
        array $commentData,
        string $sourceMethod,
        string $rawPath,
        ?string $requestId
    ): void {
        // Логика записи обогащенных данных
        // ...
    }

    public function shouldWriteEnriched(?string $taskId): bool
    {
        // Логика проверки необходимости записи
        // ...
    }
}
```

#### 3.5. Рефакторинг CommentDetailsService

**Новая структура:**
```php
<?php
declare(strict_types=1);

class CommentDetailsService
{
    private CommentFetcher $fetcher;
    private CommentBuilder $builder;
    private CommentFormatter $formatter;
    private CommentWriter $writer;
    private ActivityFirstProcessor $activityFirst;

    public function __construct(
        CommentFetcher $fetcher,
        CommentBuilder $builder,
        CommentFormatter $formatter,
        CommentWriter $writer,
        ActivityFirstProcessor $activityFirst
    ) {
        $this->fetcher = $fetcher;
        $this->builder = $builder;
        $this->formatter = $formatter;
        $this->writer = $writer;
        $this->activityFirst = $activityFirst;
    }

    public function handleCommentAdd(
        string $eventType,
        array $raw,
        array $job,
        ?string $entityId,
        string $rawPath,
        ?array $taskData
    ): void {
        if ($entityId === null) {
            return;
        }

        $commentId = $this->fetcher->getIdentity()->extractCommentId($raw['payload'] ?? []);
        $messageId = $this->fetcher->getIdentity()->extractMessageId($raw['payload'] ?? []);
        
        if ($commentId === null) {
            return;
        }

        $fetch = $this->fetcher->fetch($entityId, $commentId, $messageId, $taskData);
        
        if (is_array($fetch['data'])) {
            $details = $this->builder->build(
                $fetch['data'],
                $eventType,
                $job['requestId'] ?? null,
                $entityId,
                $commentId,
                $fetch['method'],
                $taskData
            );
            $this->writer->writeDetailsRu($eventType, $details);
        } else {
            $fallback = $this->builder->buildFallback(
                $eventType,
                $job['requestId'] ?? null,
                $entityId,
                $commentId
            );
            $this->writer->writeDetailsRu($eventType, $fallback);
        }
    }
}
```

---

## Критерии успеха рефакторинга

### Функциональные требования

- [ ] Все существующие тесты проходят
- [ ] Функциональность модуля не изменилась
- [ ] Обратная совместимость сохранена (старые функции работают)

### Качественные требования

- [ ] Каждый класс имеет одну ответственность (SRP)
- [ ] Глубина вложенности не превышает 3 уровней
- [ ] Дублирование кода устранено
- [ ] Все зависимости передаются через конструктор (DI)
- [ ] Код покрыт unit-тестами (минимум 70%)

### Метрики

- [ ] `index.php` уменьшен до < 100 строк
- [ ] `bootstrap.php` уменьшен до < 200 строк (только критичные функции)
- [ ] `CommentDetailsService` уменьшен до < 200 строк
- [ ] Каждый новый класс < 300 строк
- [ ] Цикломатическая сложность методов < 10

---

## План внедрения

### Фаза 1: Подготовка (1-2 дня)

1. Создание базовых классов исключений:
   - `InvalidRequestException`
   - `AuthenticationException`
   - `ProcessingException`

2. Создание ServiceContainer
3. Написание unit-тестов для новых компонентов

### Фаза 2: Рефакторинг index.php (3-5 дней)

1. Создание RequestValidator
2. Создание AuthMiddleware
3. Создание EventProcessor
4. Создание TaskEventHandler
5. Создание CommentEventHandler
6. Рефакторинг index.php
7. Тестирование

### Фаза 3: Рефакторинг bootstrap.php (2-3 дня)

1. Создание ServiceContainer
2. Минимизация функций-оберток
3. Миграция существующего кода
4. Тестирование

### Фаза 4: Рефакторинг CommentDetailsService (5-7 дней)

1. Создание CommentFetcher
2. Создание CommentBuilder
3. Создание CommentFormatter
4. Создание CommentWriter
5. Рефакторинг CommentDetailsService
6. Тестирование

### Фаза 5: Финальная проверка (1-2 дня)

1. Интеграционное тестирование
2. Проверка производительности
3. Обновление документации
4. Code review

---

## Риски и митигация

### Риск 1: Нарушение обратной совместимости

**Митигация:**
- Сохранение старых функций-оберток с пометкой `@deprecated`
- Постепенная миграция кода
- Тщательное тестирование

### Риск 2: Увеличение сложности кода

**Митигация:**
- Следование принципам SOLID
- Детальная документация
- Code review

### Риск 3: Снижение производительности

**Митигация:**
- Профилирование до и после рефакторинга
- Оптимизация критичных путей
- Кеширование сервисов в контейнере

---

## Связанные документы

- `05-services-architecture.md` — архитектура сервисного слоя
- `06-core-services.md` — базовые сервисы
- `09-task-services.md` — сервисы работы с задачами
- `18-error-handling.md` — обработка ошибок
- `19-security.md` — механизмы безопасности

---

## История изменений

- 2026-01-27 (UTC+3, Брест): Создан документ с планом рефакторинга монолитных частей модуля.
