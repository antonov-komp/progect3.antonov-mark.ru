# Обогащение данных: детальное описание

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает детальный процесс обогащения данных событий: от raw payload до enriched.json, маппинг событий на REST-методы, параметры запросов, загрузку справочников, отслеживание изменений полей.

---

## Общий процесс обогащения

### Входные данные

- `eventType` — тип события (например, `'ONCRMDEALADD'`)
- `entityType` — тип сущности (например, `'deal'`)
- `entityId` — ID сущности (например, `'123'`)
- `raw` — сырые данные события
- `rawPath` — путь к файлу `raw.json`

### Выходные данные

- `enriched.json` — обогащённые данные с полной информацией о сущности и справочниках

---

## Алгоритм обогащения

### Шаг 1: Определение REST-метода

```php
$method = $enrichment->resolveMethod('ONCRMDEALADD');
// Результат: 'crm.deal.get'
```

**Маппинг событий на методы:**

| Тип события | REST-метод | Параметры |
|-------------|------------|-----------|
| `ONCRMDEAL*` | `crm.deal.get` | `['id' => $entityId]` |
| `ONCRMLEAD*` | `crm.lead.get` | `['id' => $entityId]` |
| `ONCRMCONTACT*` | `crm.contact.get` | `['id' => $entityId]` |
| `ONCRMCOMPANY*` | `crm.company.get` | `['id' => $entityId]` |
| `ONCRMITEM*` | `crm.item.get` | `['entityTypeId' => $entityTypeId, 'id' => $entityId]` |
| `ONTASK*` | `tasks.task.get` | `['id' => $entityId]` |
| `ONUSER*` | `user.get` | `['id' => $entityId]` |
| `SONET_GROUP_*` | `sonet_group.get` | `['id' => $entityId]` |
| `ONCRMUSERFIELD*` | `crm.userfield.list` | `[]` |

**Особенности:**
- Для смарт-процессов (`ONCRMITEM*`) требуется `ENTITY_TYPE_ID` из payload
- Для пользовательских полей (`ONCRMUSERFIELD*`) параметры пустые

### Шаг 2: Получение обработчика сущности

```php
$handler = $this->handlers[$entityType] ?? new DefaultEntityHandler($entityType);
```

**Зарегистрированные обработчики:**
- `DealHandler` — для сделок
- `LeadHandler` — для лидов
- `ContactHandler` — для контактов
- `CompanyHandler` — для компаний
- `SmartProcessHandler` — для смарт-процессов
- `TaskHandler` — для задач
- `UserHandler` — для пользователей
- `ProjectHandler` — для проектов
- `CrmUserFieldHandler` — для пользовательских полей CRM

**Если обработчик не найден:** используется `DefaultEntityHandler`

### Шаг 3: Построение параметров запроса

```php
$params = $handler->buildParams($method, $entityId, $raw);
```

**Примеры параметров:**

**Для сделки:**
```php
['id' => '123']
```

**Для смарт-процесса:**
```php
['entityTypeId' => '128', 'id' => '456']
```

**Для пользовательских полей:**
```php
[]
```

**Обработка ошибок:**
- Если `entityId` отсутствует и требуется — выбрасывается `RuntimeException`
- Ошибка возвращается как `['error' => 'missing_entity_id']`

### Шаг 4: Выполнение REST-запроса

```php
$startedAt = microtime(true);
$result = $this->rest->call($method, $params);
$elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);
```

**Особенности:**
- Измерение времени выполнения запроса
- Поддержка повторных попыток (настраивается через `OUTGOING_WEBHOOK_REST_RETRIES`)
- Логирование ошибок через `ErrorService`

**Обработка ошибок:**
```php
if (!empty($result['error'])) {
    return ['error' => 'rest_error', 'details' => $result['error']];
}
```

### Шаг 5: Формирование данных сущности

```php
$data = [
    $entityType => $result['result'] ?? $result,
];
```

**Структура данных:**
- Ключ — тип сущности (например, `'deal'`, `'task'`)
- Значение — данные сущности из REST API

### Шаг 6: Загрузка справочников

```php
foreach ($handler->getDicts($raw) as $key => $value) {
    $data[$key] = $value;
}
```

**Справочники для разных типов сущностей:**

**Сделки (DealHandler):**
- `categories` — категории сделок (`crm.category.list`)
- `stages` — стадии сделок (`crm.status.list`)

**Лиды (LeadHandler):**
- `stages` — статусы лидов (`crm.status.list`)

**Смарт-процессы (SmartProcessHandler):**
- `types` — типы смарт-процессов (`crm.type.list`)
- `categories` — категории смарт-процесса (`crm.category.list`)

**TTL справочников:** 86400 секунд (24 часа)

**Кеширование:** через `DictCacheService`

### Шаг 7: Формирование результата

```php
return [
    'eventType' => $eventType,
    'entityType' => $entityType,
    'entityId' => $entityId,
    'enrichedAt' => outgoingWebhookNow(),
    'source' => [
        'method' => $method,
        'responseTimeMs' => $elapsedMs,
    ],
    'rawRef' => $rawPath,
    'data' => $data,
];
```

---

## Формат enriched.json

### Структура файла

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
      "STAGE_ID": "NEW",
      "OPPORTUNITY": "10000",
      "CURRENCY_ID": "RUB"
    },
    "categories": [
      {
        "ID": "1",
        "NAME": "Category 1"
      }
    ],
    "stages": [
      {
        "ID": "NEW",
        "NAME": "Новая"
      }
    ]
  }
}
```

### Описание полей

| Поле | Тип | Описание |
|------|-----|----------|
| `eventType` | string | Тип события |
| `entityType` | string | Тип сущности |
| `entityId` | string\|null | ID сущности |
| `enrichedAt` | string | Время обогащения (ISO 8601) |
| `source.method` | string | Метод REST API |
| `source.responseTimeMs` | int | Время выполнения запроса в миллисекундах |
| `rawRef` | string | Путь к файлу `raw.json` |
| `data.{entityType}` | object | Данные сущности |
| `data.{dictKey}` | array | Справочники (опционально) |

---

## Примеры обогащения для разных типов сущностей

### Сделка (ONCRMDEALADD)

**Входные данные:**
- `eventType`: `'ONCRMDEALADD'`
- `entityType`: `'deal'`
- `entityId`: `'123'`

**Процесс:**
1. Метод: `crm.deal.get`
2. Параметры: `['id' => '123']`
3. Обработчик: `DealHandler`
4. Справочники: `categories`, `stages`

**Результат:**
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

### Задача (ONTASKADD)

**Входные данные:**
- `eventType`: `'ONTASKADD'`
- `entityType`: `'task'`
- `entityId`: `'123'`

**Процесс:**
1. Метод: `tasks.task.get`
2. Параметры: `['id' => '123']`
3. Обработчик: `TaskHandler`
4. Справочники: нет

**Результат:**
```json
{
  "eventType": "ONTASKADD",
  "entityType": "task",
  "entityId": "123",
  "enrichedAt": "2026-01-26T12:00:00+03:00",
  "source": {
    "method": "tasks.task.get",
    "responseTimeMs": 180
  },
  "data": {
    "task": {
      "ID": "123",
      "TITLE": "Task title",
      "CREATED_BY": "456",
      "GROUP_ID": "15"
    }
  }
}
```

### Смарт-процесс (ONCRMITEMADD)

**Входные данные:**
- `eventType`: `'ONCRMITEMADD'`
- `entityType`: `'smart_process'`
- `entityId`: `'456'`
- `raw.payload.data.FIELDS.ENTITY_TYPE_ID`: `'128'`

**Процесс:**
1. Метод: `crm.item.get`
2. Параметры: `['entityTypeId' => '128', 'id' => '456']`
3. Обработчик: `SmartProcessHandler`
4. Справочники: `types`, `categories`

**Результат:**
```json
{
  "eventType": "ONCRMITEMADD",
  "entityType": "smart_process",
  "entityId": "456",
  "enrichedAt": "2026-01-26T12:00:00+03:00",
  "source": {
    "method": "crm.item.get",
    "responseTimeMs": 300
  },
  "data": {
    "smart_process": {
      "ID": "456",
      "TITLE": "Item title"
    },
    "types": [...],
    "categories": [...]
  }
}
```

---

## Отслеживание изменений полей

### Когда выполняется

Отслеживание изменений выполняется после обогащения для событий обновления (`*UPDATE`).

**Вызов:**
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

### Алгоритм

1. **Загрузка предыдущего состояния:**
   ```php
   $statePath = $stateStorage->getPath($entityType, $entityId);
   $previous = json_decode(file_get_contents($statePath), true);
   ```

2. **Сравнение полей:**
   ```php
   foreach ($fields as $field) {
       $oldValue = $before[$field] ?? null;
       $newValue = $after[$field] ?? null;
       if (!ValueComparator::valuesEqual($oldValue, $newValue)) {
           $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
       }
   }
   ```

3. **Сохранение изменений:**
   - Файл изменений: `logs/field-changes/{entityType}_{entityId}_{timestamp}.json`
   - Лог изменений: `logs/field-changes/field-changes.log`

4. **Сохранение текущего состояния:**
   - Файл состояния: `logs/state/{entityType}_{entityId}.json`

### Формат файла изменений

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

## Обработка ошибок обогащения

### Типы ошибок

1. **Неизвестный тип события:**
   ```json
   {
     "error": "unknown_event_type"
   }
   ```

2. **Отсутствует entityId:**
   ```json
   {
     "error": "missing_entity_id"
   }
   ```

3. **Ошибка REST API:**
   ```json
   {
     "error": "rest_error",
     "details": {
       "error": "invalid_token",
       "error_description": "..."
     }
   }
   ```

### Повторные попытки

**Настройки:**
- `OUTGOING_WEBHOOK_REST_RETRIES` — количество попыток (по умолчанию: `0`)
- `OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS` — задержка между попытками (по умолчанию: `0`)

**Логика:**
- При ошибке REST API выполняется повтор с задержкой
- Если все попытки исчерпаны — возврат ошибки

### Логирование ошибок

Ошибки логируются через `ErrorService`:
- Файл: `logs/errors/error-YYYYMMDD.log`
- Формат: line-based JSON

---

## Кеширование справочников

### Механизм кеширования

**Сервис:** `DictCacheService`

**TTL:** 86400 секунд (24 часа)

**Формат файла кеша:**
```json
{
  "cachedAt": "2026-01-26T12:00:00+03:00",
  "data": {
    // Данные справочника
  }
}
```

**Путь к файлу:** `logs/dicts/{name}.json`

### Примеры кешируемых справочников

- `deal_categories` — категории сделок
- `deal_stages` — стадии сделок
- `lead_stages` — статусы лидов
- `crm_types` — типы смарт-процессов
- `crm_item_categories_{entityTypeId}` — категории смарт-процесса

### Инвалидация кеша

Кеш автоматически инвалидируется при истечении TTL (24 часа).

---

## Производительность

### Типичное время обогащения

- **Сделки:** 200-400 мс
- **Лиды:** 200-400 мс
- **Задачи:** 150-300 мс
- **Смарт-процессы:** 300-500 мс (с загрузкой справочников)

### Факторы, влияющие на производительность

1. **Загрузка справочников** — увеличивает время на 100-200 мс
2. **Размер данных сущности** — большие сущности обрабатываются дольше
3. **Сетевая задержка** — зависит от расположения Bitrix24
4. **Кеш справочников** — использование кеша ускоряет обработку

---

## Связанные документы

- `07-enrichment-services.md` — описание сервисов обогащения
- `06-core-services.md` — базовые сервисы (RestService, DictCacheService)
- `12-state-tracking.md` — отслеживание изменений полей
- `08-queue-services.md` — сервисы очереди

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с детальным описанием процесса обогащения.
