# Архитектура сервисов модуля outgoing-webhook

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Модуль `outgoing-webhook` построен на основе **Service Layer** архитектуры с использованием **Dependency Injection** (DI). Все бизнес-логика вынесена в сервисы, а `bootstrap.php` предоставляет shim-функции для обратной совместимости.

---

## Структура папки services/

```
outgoing-webhook/services/
├── bootstrap.php                    # Автозагрузка всех сервисов
├── Config/
│   └── ConfigService.php            # Конфигурация (env, config.local.php)
├── Core/
│   └── FilesystemService.php        # Файловые операции
├── Http/
│   └── RequestService.php           # HTTP-запросы, payload, нормализация
├── Security/
│   └── AccessService.php            # Безопасность (токены, IP)
├── Identity/
│   └── EntityIdentityService.php    # Извлечение ID сущностей
├── Logging/
│   ├── ErrorService.php             # Логирование ошибок
│   ├── LogValueFormatter.php        # Форматирование и маскирование
│   └── QueueStepLogger.php          # Логирование шагов очереди
├── Rest/
│   └── RestService.php              # Обёртка над Bitrix24Client
├── Dicts/
│   └── DictCacheService.php         # Кеширование справочников
├── Enrichment/
│   ├── EnrichmentService.php        # Обогащение данных
│   ├── StateStorage.php              # Хранение состояний
│   ├── ValueComparator.php          # Сравнение значений
│   └── EntityHandlers/              # Обработчики типов сущностей
│       ├── EntityHandlerInterface.php
│       ├── DefaultEntityHandler.php
│       ├── DealHandler.php
│       ├── LeadHandler.php
│       ├── ContactHandler.php
│       ├── CompanyHandler.php
│       ├── SmartProcessHandler.php
│       ├── TaskHandler.php
│       ├── UserHandler.php
│       ├── ProjectHandler.php
│       └── CrmUserFieldHandler.php
├── Queue/
│   ├── QueueService.php             # Управление очередью
│   ├── QueueJob.php                  # Представление задания
│   ├── QueueRunner.php               # Оркестрация обработки
│   └── JobStateService.php           # Управление состоянием заданий
├── Task/
│   ├── TaskDetailsService.php       # Детали задач
│   ├── TaskFilesService.php         # Файлы задач
│   └── CommentDetailsService.php    # Детали комментариев
└── Crm/
    └── DealFileService.php           # Файлы сделок
```

---

## Принципы архитектуры

### 1. Service Layer Pattern

Все бизнес-логика инкапсулирована в сервисах. Каждый сервис отвечает за определённую область функциональности:

- **ConfigService** — конфигурация
- **FilesystemService** — файловые операции
- **RequestService** — HTTP-запросы
- **AccessService** — безопасность
- **EntityIdentityService** — идентификация сущностей
- **ErrorService** — логирование ошибок
- **RestService** — REST API
- **EnrichmentService** — обогащение данных
- **QueueService** — управление очередью

### 2. Dependency Injection

Сервисы получают зависимости через конструктор:

```php
class RestService
{
    private Bitrix24Client $client;
    private ConfigService $config;
    private ErrorService $errors;

    public function __construct(
        Bitrix24Client $client,
        ConfigService $config,
        ErrorService $errors
    ) {
        $this->client = $client;
        $this->config = $config;
        $this->errors = $errors;
    }
}
```

### 3. Service Locator Pattern (в bootstrap.php)

Для обратной совместимости используется Service Locator через функцию `outgoingWebhookService()`:

```php
function outgoingWebhookService(string $key)
{
    static $services = [];
    if (isset($services[$key])) {
        return $services[$key];
    }

    switch ($key) {
        case 'config':
            return $services[$key] = new ConfigService();
        case 'filesystem':
            return $services[$key] = new FilesystemService();
        // ...
    }
}
```

**Примечание:** В новых компонентах (например, `process-queue.php`) используется явная инициализация сервисов через `outgoingWebhookGetServices()`.

### 4. Handler Pattern (для EntityHandlers)

Для обработки разных типов сущностей используется паттерн Handler:

```php
interface EntityHandlerInterface
{
    public function getEntityType(): string;
    public function buildParams(string $method, ?string $entityId, array $raw): array;
    public function getDicts(array $raw): array;
}
```

Каждый тип сущности имеет свой обработчик:
- `DealHandler` — для сделок
- `LeadHandler` — для лидов
- `TaskHandler` — для задач
- И т.д.

---

## Диаграмма зависимостей сервисов

```
┌─────────────────┐
│  ConfigService  │ (независимый)
└────────┬────────┘
         │
         ├─────────────────────────────────────────────┐
         │                                             │
         ▼                                             ▼
┌─────────────────┐                          ┌─────────────────┐
│ RequestService  │                          │  AccessService  │
└────────┬────────┘                          └─────────────────┘
         │
         ├─────────────────────────────────────────────┐
         │                                             │
         ▼                                             ▼
┌──────────────────────┐                    ┌──────────────────────┐
│ EntityIdentityService│                    │   FilesystemService │
└──────────────────────┘                    └──────────┬───────────┘
                                                      │
         ┌────────────────────────────────────────────┘
         │
         ▼
┌─────────────────┐
│  ErrorService   │
└────────┬────────┘
         │
         ├─────────────────────────────────────────────┐
         │                                             │
         ▼                                             ▼
┌─────────────────┐                          ┌─────────────────┐
│  RestService     │                          │ LogValueFormatter│
└────────┬────────┘                          └─────────────────┘
         │
         ├─────────────────────────────────────────────┐
         │                                             │
         ▼                                             ▼
┌──────────────────────┐                    ┌──────────────────────┐
│  DictCacheService    │                    │  EnrichmentService  │
└──────────────────────┘                    └──────────┬──────────┘
                                                        │
                                                        ▼
                                              ┌──────────────────────┐
                                              │  EntityHandlers[]   │
                                              └──────────────────────┘
```

---

## Инициализация сервисов

### В bootstrap.php (Service Locator)

```php
function outgoingWebhookService(string $key)
{
    static $services = [];
    // Singleton для каждого сервиса
    // Ленивая инициализация
}
```

### В process-queue.php (явная инициализация)

```php
function outgoingWebhookGetServices(): array
{
    static $services = null;
    if ($services !== null) {
        return $services;
    }

    // Создание всех сервисов с зависимостями
    $config = new ConfigService();
    $filesystem = new FilesystemService();
    $request = new RequestService($config);
    // ...

    return $services;
}
```

---

## Связь с bootstrap.php

`bootstrap.php` предоставляет shim-функции, которые делегируют вызовы сервисам:

```php
// В bootstrap.php
function outgoingWebhookGetSetting(string $key, ?string $default = null): ?string
{
    return outgoingWebhookService('config')->get($key, $default);
}

function outgoingWebhookLogError(string $message, array $context = []): void
{
    outgoingWebhookService('errors')->log($message, $context);
}
```

Это обеспечивает:
- **Обратную совместимость** — старый код продолжает работать
- **Единую точку входа** — все функции доступны через `bootstrap.php`
- **Прозрачность** — можно постепенно мигрировать на прямые вызовы сервисов

---

## Группировка сервисов по назначению

### Core Services (базовые)
- `ConfigService` — конфигурация
- `FilesystemService` — файловые операции
- `RequestService` — HTTP-запросы

### Security Services (безопасность)
- `AccessService` — валидация токенов и IP

### Identity Services (идентификация)
- `EntityIdentityService` — извлечение ID сущностей

### Logging Services (логирование)
- `ErrorService` — логирование ошибок
- `LogValueFormatter` — форматирование и маскирование
- `QueueStepLogger` — логирование шагов очереди

### Integration Services (интеграция)
- `RestService` — REST API Bitrix24
- `DictCacheService` — кеширование справочников

### Enrichment Services (обогащение)
- `EnrichmentService` — обогащение данных
- `StateStorage` — хранение состояний
- `ValueComparator` — сравнение значений
- `EntityHandlers[]` — обработчики типов сущностей

### Queue Services (очередь)
- `QueueService` — управление очередью
- `QueueJob` — представление задания
- `QueueRunner` — оркестрация обработки
- `JobStateService` — управление состоянием

### Task Services (задачи)
- `TaskDetailsService` — детали задач
- `TaskFilesService` — файлы задач
- `CommentDetailsService` — детали комментариев

### CRM Services (CRM)
- `DealFileService` — файлы сделок

---

## Паттерны проектирования

### 1. Singleton (через Service Locator)

Сервисы создаются один раз и переиспользуются:

```php
function outgoingWebhookService(string $key)
{
    static $services = [];
    if (isset($services[$key])) {
        return $services[$key]; // Возврат существующего экземпляра
    }
    // Создание нового экземпляра
}
```

### 2. Factory Pattern (для EntityHandlers)

`EnrichmentService` создаёт обработчики для каждого типа сущности:

```php
$handlers = [
    new DealHandler($dicts),
    new LeadHandler($dicts),
    // ...
];

$enrichment = new EnrichmentService($rest, $errors, $stateStorage, $handlers);
```

### 3. Strategy Pattern (для EntityHandlers)

Каждый обработчик реализует свою стратегию обогащения:

```php
interface EntityHandlerInterface
{
    public function buildParams(string $method, ?string $entityId, array $raw): array;
    public function getDicts(array $raw): array;
}
```

### 4. Template Method (для обработки очереди)

`QueueRunner` определяет общий алгоритм обработки, делегируя детали сервисам:

```php
public function run(int $limit): array
{
    // 1. Восстановление зависших заданий
    $this->jobState->recoverProcessing();
    
    // 2. Обработка каждого задания
    foreach ($this->queue->listPending($limit) as $pendingJob) {
        // 3. Обогащение через EnrichmentService
        $enriched = $this->enrichment->buildEnriched(...);
        
        // 4. Обработка задач/комментариев
        if (str_starts_with($eventType, 'ONTASK')) {
            $this->commentDetails->handleCommentAdd(...);
        }
    }
}
```

---

## Расширяемость

### Добавление нового типа сущности

1. Создать новый `EntityHandler`:
```php
class CustomEntityHandler implements EntityHandlerInterface
{
    public function getEntityType(): string
    {
        return 'custom';
    }
    
    public function buildParams(string $method, ?string $entityId, array $raw): array
    {
        return ['id' => $entityId];
    }
    
    public function getDicts(array $raw): array
    {
        return [];
    }
}
```

2. Зарегистрировать в `EnrichmentService`:
```php
$handlers[] = new CustomEntityHandler($dicts);
```

3. Добавить маппинг в `EnrichmentService::resolveMethod()`:
```php
if (str_starts_with($eventType, 'ONCUSTOM')) {
    return 'custom.entity.get';
}
```

### Добавление нового сервиса

1. Создать класс сервиса в соответствующей папке
2. Зарегистрировать в `bootstrap.php`:
```php
case 'customService':
    return $services[$key] = new CustomService(...);
```

3. Добавить shim-функцию (опционально):
```php
function outgoingWebhookCustomAction(): void
{
    outgoingWebhookService('customService')->action();
}
```

---

## Преимущества архитектуры

1. **Разделение ответственности** — каждый сервис отвечает за свою область
2. **Тестируемость** — сервисы можно тестировать изолированно
3. **Переиспользование** — сервисы используются в разных контекстах
4. **Расширяемость** — легко добавлять новые сервисы и обработчики
5. **Поддерживаемость** — изменения локализованы в конкретных сервисах

---

## Связанные документы

- `06-core-services.md` — детальное описание Core Services
- `07-enrichment-services.md` — детальное описание Enrichment Services
- `08-queue-services.md` — детальное описание Queue Services
- `09-task-services.md` — детальное описание Task Services
- `bootstrap/README.md` — описание shim-функций

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием архитектуры сервисов.
