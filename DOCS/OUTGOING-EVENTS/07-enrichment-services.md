# Enrichment Services: сервисы обогащения данных

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает сервисы, отвечающие за обогащение данных событий через Bitrix24 REST API: определение REST-методов, выполнение запросов, загрузку справочников, отслеживание изменений полей.

---

## EnrichmentService

**Файл:** `outgoing-webhook/services/Enrichment/EnrichmentService.php`

### Назначение

Основной сервис обогащения данных. Определяет REST-метод по типу события, выполняет запрос через EntityHandler, загружает справочники и формирует обогащённые данные.

### Зависимости

- `RestService` — для выполнения REST-запросов
- `ErrorService` — для логирования ошибок
- `StateStorage` — для хранения состояний
- `EntityHandlerInterface[]` — обработчики типов сущностей

### Методы

#### `resolveMethod(string $eventType): ?string`

Определение REST-метода Bitrix24 по типу события.

**Параметры:**
- `$eventType` — тип события (например, `'ONCRMDEALADD'`)

**Возвращает:** метод REST API или `null` если не найден

**Маппинг событий на методы:**

| Тип события | REST-метод |
|-------------|------------|
| `ONCRMDEAL*` | `crm.deal.get` |
| `ONCRMLEAD*` | `crm.lead.get` |
| `ONCRMCONTACT*` | `crm.contact.get` |
| `ONCRMCOMPANY*` | `crm.company.get` |
| `ONCRMITEM*` | `crm.item.get` |
| `ONTASK*` | `tasks.task.get` |
| `ONUSER*` | `user.get` |
| `SONET_GROUP_*` | `sonet_group.get` |
| `ONCRMUSERFIELD*` | `crm.userfield.list` |

**Пример:**
```php
$method = $enrichment->resolveMethod('ONCRMDEALADD');
// Результат: 'crm.deal.get'
```

#### `buildEnriched(string $eventType, string $entityType, ?string $entityId, array $raw, string $rawPath): array`

Обогащение данных события через REST API.

**Параметры:**
- `$eventType` — тип события
- `$entityType` — тип сущности (например, `'deal'`)
- `$entityId` — ID сущности или `null`
- `$raw` — сырые данные события
- `$rawPath` — путь к файлу `raw.json`

**Возвращает:** массив обогащённых данных или массив с ошибкой

**Формат результата (успех):**
```json
{
  "eventType": "ONCRMDEALADD",
  "entityType": "deal",
  "entityId": "123",
  "enrichedAt": "2026-01-26T12:00:00+03:00",
  "source": {
    "method": "crm.deal.get",
    "responseTimeMs": 240
  },
  "rawRef": "/path/to/raw.json",
  "data": {
    "deal": {
      "ID": "123",
      "TITLE": "Deal title",
      "STAGE_ID": "NEW"
    },
    "categories": [...],
    "stages": [...]
  }
}
```

**Формат результата (ошибка):**
```json
{
  "error": "rest_error",
  "details": {
    "error": "invalid_token",
    "error_description": "..."
  }
}
```

**Алгоритм:**
1. Определение REST-метода через `resolveMethod()`
2. Получение обработчика для типа сущности (или `DefaultEntityHandler`)
3. Построение параметров запроса через `handler->buildParams()`
4. Выполнение REST-запроса с измерением времени
5. Обработка ошибок REST API
6. Формирование данных с загрузкой справочников через `handler->getDicts()`
7. Возврат обогащённых данных

**Пример:**
```php
$enriched = $enrichment->buildEnriched(
    'ONCRMDEALADD',
    'deal',
    '123',
    $raw,
    '/path/to/raw.json'
);

if (!empty($enriched['error'])) {
    // Обработка ошибки
}
```

#### `extractEntityTypeId(array $payload): ?string`

Извлечение `ENTITY_TYPE_ID` из payload (для смарт-процессов).

**Параметры:**
- `$payload` — payload события

**Возвращает:** `ENTITY_TYPE_ID` или `null`

**Приоритет источников:**
1. `payload.data.FIELDS.ENTITY_TYPE_ID`
2. `payload.data.ENTITY_TYPE_ID`

**Использование:** для определения типа смарт-процесса

#### `detectFieldChanges(string $entityType, string $entityId, array $current, string $eventType): void`

Отслеживание изменений полей сущности.

**Параметры:**
- `$entityType` — тип сущности
- `$entityId` — ID сущности
- `$current` — текущие данные сущности
- `$eventType` — тип события

**Алгоритм:**
1. Загрузка предыдущего состояния из `StateStorage`
2. Сравнение полей через `ValueComparator`
3. Сохранение изменений в `field-changes/`
4. Сохранение текущего состояния

**Использование:** вызывается после обогащения для отслеживания изменений

---

## EntityHandlerInterface

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/EntityHandlerInterface.php`

### Назначение

Интерфейс для обработчиков типов сущностей. Каждый тип сущности имеет свой обработчик, который определяет параметры REST-запроса и загружает справочники.

### Методы интерфейса

#### `getEntityType(): string`

Получение типа сущности, обрабатываемой этим handler'ом.

**Возвращает:** тип сущности (например, `'deal'`, `'lead'`, `'task'`)

#### `buildParams(string $method, ?string $entityId, array $raw): array`

Построение параметров для REST-запроса.

**Параметры:**
- `$method` — метод REST API
- `$entityId` — ID сущности или `null`
- `$raw` — сырые данные события

**Возвращает:** массив параметров для REST-запроса

**Может выбрасывать:** `RuntimeException` если параметры не могут быть построены

**Пример:**
```php
$params = $handler->buildParams('crm.deal.get', '123', $raw);
// Результат: ['id' => '123']
```

#### `getDicts(array $raw): array`

Получение справочников для сущности.

**Параметры:**
- `$raw` — сырые данные события

**Возвращает:** массив справочников (ключ → данные)

**Пример:**
```php
$dicts = $handler->getDicts($raw);
// Результат: [
//     'categories' => [...],
//     'stages' => [...]
// ]
```

---

## Реализации EntityHandler

### DefaultEntityHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/DefaultEntityHandler.php`

**Назначение:** Базовый обработчик для всех типов сущностей.

**Особенности:**
- Параметры: `['id' => $entityId]`
- Справочники: пустой массив
- Для `crm.userfield.list` — параметры пустые

**Использование:** для типов сущностей без специальной обработки

### DealHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/DealHandler.php`

**Назначение:** Обработчик для сделок.

**Загружаемые справочники:**
- `categories` — категории сделок (`crm.category.list`)
- `stages` — стадии сделок (`crm.status.list`)

**TTL справочников:** 86400 секунд (24 часа)

**Пример:**
```php
$handler = new DealHandler($dicts);
$dicts = $handler->getDicts($raw);
// Результат: [
//     'categories' => [...],
//     'stages' => [...]
// ]
```

### LeadHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/LeadHandler.php`

**Назначение:** Обработчик для лидов.

**Загружаемые справочники:**
- `statuses` — статусы лидов (`crm.status.list`)
- `sources` — источники лидов (`crm.status.list`)

### ContactHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/ContactHandler.php`

**Назначение:** Обработчик для контактов.

**Загружаемые справочники:**
- `types` — типы контактов (`crm.contact.list`)

### CompanyHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/CompanyHandler.php`

**Назначение:** Обработчик для компаний.

**Загружаемые справочники:**
- `types` — типы компаний (`crm.company.list`)

### SmartProcessHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/SmartProcessHandler.php`

**Назначение:** Обработчик для смарт-процессов.

**Особенности:**
- Использует `ENTITY_TYPE_ID` из payload
- Параметры: `['entityTypeId' => $entityTypeId, 'id' => $entityId]`

### TaskHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/TaskHandler.php`

**Назначение:** Обработчик для задач.

**Особенности:**
- Не загружает справочники (используется `DefaultEntityHandler`)

### UserHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/UserHandler.php`

**Назначение:** Обработчик для пользователей.

**Особенности:**
- Не загружает справочники (используется `DefaultEntityHandler`)

### ProjectHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/ProjectHandler.php`

**Назначение:** Обработчик для проектов (групп).

**Особенности:**
- Не загружает справочники (используется `DefaultEntityHandler`)

### CrmUserFieldHandler

**Файл:** `outgoing-webhook/services/Enrichment/EntityHandlers/CrmUserFieldHandler.php`

**Назначение:** Обработчик для пользовательских полей CRM.

**Особенности:**
- Параметры: пустой массив (для `crm.userfield.list`)

---

## StateStorage

**Файл:** `outgoing-webhook/services/Enrichment/StateStorage.php`

### Назначение

Хранение снимков состояния сущностей для отслеживания изменений полей.

### Методы

#### `getPath(string $entityType, string $entityId): string`

Получение пути к файлу состояния.

**Параметры:**
- `$entityType` — тип сущности
- `$entityId` — ID сущности

**Возвращает:** путь к файлу состояния

**Формат пути:** `logs/state/{entityType}_{entityId}.json`

**Пример:**
```php
$path = $stateStorage->getPath('deal', '123');
// Результат: 'logs/state/deal_123.json'
```

#### `detectFieldChanges(string $entityType, string $entityId, array $current, string $eventType): void`

Обнаружение изменений полей сущности.

**Параметры:**
- `$entityType` — тип сущности
- `$entityId` — ID сущности
- `$current` — текущие данные сущности
- `$eventType` — тип события

**Алгоритм:**
1. Загрузка предыдущего состояния из файла
2. Сравнение всех полей через `ValueComparator::valuesEqual()`
3. Если есть изменения:
   - Сохранение изменений в `logs/field-changes/{entityType}_{entityId}_{timestamp}.json`
   - Запись в `logs/field-changes/field-changes.log`
4. Сохранение текущего состояния в файл состояния

**Формат файла состояния:**
```json
{
  "savedAt": "2026-01-26T12:00:00+03:00",
  "entityType": "deal",
  "entityId": "123",
  "data": {
    "ID": "123",
    "TITLE": "Deal title",
    "STAGE_ID": "NEW"
  }
}
```

**Формат файла изменений:**
```json
{
  "changedAt": "2026-01-26T12:05:00+03:00",
  "eventType": "ONCRMDEALUPDATE",
  "entityType": "deal",
  "entityId": "123",
  "changes": {
    "STAGE_ID": {
      "old": "NEW",
      "new": "WON"
    },
    "OPPORTUNITY": {
      "old": "10000",
      "new": "15000"
    }
  }
}
```

---

## ValueComparator

**Файл:** `outgoing-webhook/services/Enrichment/ValueComparator.php`

### Назначение

Сравнение значений для определения изменений полей. Поддерживает рекурсивное сравнение массивов.

### Методы

#### `sortRecursive($value)`

Рекурсивная сортировка значения (массива).

**Параметры:**
- `$value` — значение для сортировки

**Возвращает:** отсортированное значение

**Алгоритм:**
- Для ассоциативных массивов — `ksort()`
- Для индексированных массивов — `sort()`
- Рекурсивная обработка вложенных массивов

**Использование:** для нормализации значений перед сравнением

#### `valuesEqual($left, $right): bool`

Сравнение двух значений.

**Параметры:**
- `$left` — первое значение
- `$right` — второе значение

**Возвращает:** `true` если значения равны, `false` если нет

**Алгоритм:**
1. Рекурсивная сортировка обоих значений
2. Сравнение через `json_encode()`

**Пример:**
```php
$equal = ValueComparator::valuesEqual(
    ['a' => 1, 'b' => 2],
    ['b' => 2, 'a' => 1]
);
// Результат: true (порядок ключей не важен)
```

---

## Регистрация обработчиков

Обработчики регистрируются при создании `EnrichmentService`:

```php
$handlers = [
    new DealHandler($dicts),
    new LeadHandler($dicts),
    new SmartProcessHandler($dicts),
    new TaskHandler(),
    new UserHandler(),
    new ProjectHandler(),
    new CrmUserFieldHandler(),
    new ContactHandler($dicts),
    new CompanyHandler($dicts),
];

$enrichment = new EnrichmentService($rest, $errors, $stateStorage, $handlers);
```

Каждый обработчик регистрируется по типу сущности:
```php
$this->handlers[$handler->getEntityType()] = $handler;
```

Если обработчик не найден — используется `DefaultEntityHandler`.

---

## Процесс обогащения

### Шаг 1: Определение метода

```php
$method = $enrichment->resolveMethod('ONCRMDEALADD');
// Результат: 'crm.deal.get'
```

### Шаг 2: Получение обработчика

```php
$handler = $this->handlers['deal'] ?? new DefaultEntityHandler('deal');
```

### Шаг 3: Построение параметров

```php
$params = $handler->buildParams('crm.deal.get', '123', $raw);
// Результат: ['id' => '123']
```

### Шаг 4: Выполнение REST-запроса

```php
$result = $this->rest->call('crm.deal.get', ['id' => '123']);
```

### Шаг 5: Загрузка справочников

```php
$dicts = $handler->getDicts($raw);
// Результат: ['categories' => [...], 'stages' => [...]]
```

### Шаг 6: Формирование результата

```php
$enriched = [
    'eventType' => 'ONCRMDEALADD',
    'entityType' => 'deal',
    'entityId' => '123',
    'enrichedAt' => '2026-01-26T12:00:00+03:00',
    'source' => [
        'method' => 'crm.deal.get',
        'responseTimeMs' => 240
    ],
    'rawRef' => '/path/to/raw.json',
    'data' => [
        'deal' => $result['result'],
        'categories' => $dicts['categories'],
        'stages' => $dicts['stages']
    ]
];
```

---

## Связанные документы

- `05-services-architecture.md` — общая архитектура сервисов
- `06-core-services.md` — базовые сервисы (RestService, DictCacheService)
- `11-enrichment-detailed.md` — детальное описание процесса обогащения
- `12-state-tracking.md` — отслеживание изменений полей

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием Enrichment Services.
