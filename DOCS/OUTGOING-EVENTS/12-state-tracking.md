# StateStorage и отслеживание изменений полей

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает механизм отслеживания изменений полей сущностей через `StateStorage`: хранение снимков состояния, сравнение состояний, определение изменённых полей, логирование изменений.

---

## StateStorage

**Файл:** `outgoing-webhook/services/Enrichment/StateStorage.php`

### Назначение

Хранение снимков состояния сущностей для отслеживания изменений полей между событиями.

### Структура хранения

**Директория:** `outgoing-webhook/logs/state/`

**Формат имени файла:** `{entityType}_{entityId}.json`

**Примеры:**
- `deal_123.json` — состояние сделки с ID 123
- `task_456.json` — состояние задачи с ID 456
- `lead_789.json` — состояние лида с ID 789

---

## Методы StateStorage

### `getPath(string $entityType, string $entityId): string`

Получение пути к файлу состояния.

**Параметры:**
- `$entityType` — тип сущности (например, `'deal'`)
- `$entityId` — ID сущности (например, `'123'`)

**Возвращает:** путь к файлу состояния

**Пример:**
```php
$path = $stateStorage->getPath('deal', '123');
// Результат: 'logs/state/deal_123.json'
```

### `detectFieldChanges(string $entityType, string $entityId, array $current, string $eventType): void`

Обнаружение изменений полей сущности.

**Параметры:**
- `$entityType` — тип сущности
- `$entityId` — ID сущности
- `$current` — текущие данные сущности
- `$eventType` — тип события

**Алгоритм:**
1. Загрузка предыдущего состояния из файла
2. Сравнение всех полей через `ValueComparator`
3. Сохранение изменений (если есть)
4. Сохранение текущего состояния

---

## Формат файла состояния

### Структура

```json
{
  "savedAt": "2026-01-26T12:00:00+03:00",
  "entityType": "deal",
  "entityId": "123",
  "data": {
    "ID": "123",
    "TITLE": "Deal title",
    "STAGE_ID": "NEW",
    "OPPORTUNITY": "10000",
    "CURRENCY_ID": "RUB"
  }
}
```

### Описание полей

| Поле | Тип | Описание |
|------|-----|----------|
| `savedAt` | string | Время сохранения состояния (ISO 8601) |
| `entityType` | string | Тип сущности |
| `entityId` | string | ID сущности |
| `data` | object | Данные сущности (все поля) |

---

## Алгоритм отслеживания изменений

### Шаг 1: Загрузка предыдущего состояния

```php
$statePath = $this->getPath($entityType, $entityId);
$previous = null;
if (file_exists($statePath)) {
    $previous = json_decode(file_get_contents($statePath), true);
}
```

**Если файл не существует:**
- Это первое событие для сущности
- Изменений нет
- Сохраняется только текущее состояние

### Шаг 2: Сравнение полей

```php
if (is_array($previous) && isset($previous['data']) && is_array($previous['data'])) {
    $before = $previous['data'];
    $after = $current;
    $fields = array_unique(array_merge(array_keys($before), array_keys($after)));

    $changes = [];
    foreach ($fields as $field) {
        $oldValue = $before[$field] ?? null;
        $newValue = $after[$field] ?? null;
        if (!ValueComparator::valuesEqual($oldValue, $newValue)) {
            $changes[$field] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }
    }
}
```

**Особенности:**
- Сравниваются все поля из обоих состояний
- Используется `ValueComparator::valuesEqual()` для сравнения
- Учитываются новые поля (которые появились)
- Учитываются удалённые поля (которые исчезли)

### Шаг 3: Сохранение изменений

```php
if (!empty($changes)) {
    $changesDir = dirname($this->stateDir) . '/field-changes';
    outgoingWebhookSafeMkdir($changesDir);

    $entry = [
        'changedAt' => outgoingWebhookNow(),
        'eventType' => $eventType,
        'entityType' => $entityType,
        'entityId' => $entityId,
        'changes' => $changes,
    ];

    $changeFile = sprintf(
        '%s/%s_%s_%s.json',
        $changesDir,
        $entityType,
        $entityId,
        date('Ymd_His')
    );

    outgoingWebhookWriteJson($changeFile, $entry);
    outgoingWebhookAppendLine($changesDir . '/field-changes.log', json_encode($entry, JSON_UNESCAPED_SLASHES));
}
```

**Файлы изменений:**
- `logs/field-changes/{entityType}_{entityId}_{timestamp}.json` — отдельный файл для каждого изменения
- `logs/field-changes/field-changes.log` — общий лог всех изменений (line-based JSON)

### Шаг 4: Сохранение текущего состояния

```php
outgoingWebhookWriteJson($statePath, [
    'savedAt' => outgoingWebhookNow(),
    'entityType' => $entityType,
    'entityId' => $entityId,
    'data' => $current,
]);
```

**Важно:** текущее состояние сохраняется всегда, даже если изменений нет.

---

## Формат файла изменений

### Структура

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
    },
    "ASSIGNED_BY_ID": {
      "old": "456",
      "new": "789"
    }
  }
}
```

### Описание полей

| Поле | Тип | Описание |
|------|-----|----------|
| `changedAt` | string | Время изменения (ISO 8601) |
| `eventType` | string | Тип события |
| `entityType` | string | Тип сущности |
| `entityId` | string | ID сущности |
| `changes` | object | Изменённые поля (ключ → `{old, new}`) |

### Примеры изменений

**Изменение одного поля:**
```json
{
  "STAGE_ID": {
    "old": "NEW",
    "new": "WON"
  }
}
```

**Изменение нескольких полей:**
```json
{
  "STAGE_ID": {
    "old": "NEW",
    "new": "WON"
  },
  "OPPORTUNITY": {
    "old": "10000",
    "new": "15000"
  }
}
```

**Добавление нового поля:**
```json
{
  "NEW_FIELD": {
    "old": null,
    "new": "value"
  }
}
```

**Удаление поля:**
```json
{
  "OLD_FIELD": {
    "old": "value",
    "new": null
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
- Для ассоциативных массивов — `ksort()` (сортировка по ключам)
- Для индексированных массивов — `sort()` (сортировка значений)
- Рекурсивная обработка вложенных массивов

**Пример:**
```php
$sorted = ValueComparator::sortRecursive([
    'b' => 2,
    'a' => 1,
    'c' => ['z' => 3, 'x' => 1]
]);
// Результат: ['a' => 1, 'b' => 2, 'c' => ['x' => 1, 'z' => 3]]
```

#### `valuesEqual($left, $right): bool`

Сравнение двух значений.

**Параметры:**
- `$left` — первое значение
- `$right` — второе значение

**Возвращает:** `true` если значения равны, `false` если нет

**Алгоритм:**
1. Рекурсивная сортировка обоих значений
2. Сравнение через `json_encode()`

**Примеры:**
```php
// Порядок ключей не важен
ValueComparator::valuesEqual(
    ['a' => 1, 'b' => 2],
    ['b' => 2, 'a' => 1]
); // true

// Порядок элементов в массиве важен
ValueComparator::valuesEqual(
    [1, 2, 3],
    [3, 2, 1]
); // false

// Вложенные массивы
ValueComparator::valuesEqual(
    ['a' => ['x' => 1, 'y' => 2]],
    ['a' => ['y' => 2, 'x' => 1]]
); // true
```

---

## Использование в обогащении

### Вызов в QueueRunner

```php
if ($entityId !== null && isset($enriched['data'][$entityType]) && is_array($enriched['data'][$entityType])) {
    $this->enrichment->detectFieldChanges(
        $entityType,
        $entityId,
        $enriched['data'][$entityType],
        $eventType
    );
}
```

**Условия:**
- `entityId` должен быть не `null`
- Данные сущности должны быть в `enriched.data.{entityType}`
- Выполняется только после успешного обогащения

### Когда выполняется

Отслеживание изменений выполняется для:
- Событий обновления (`*UPDATE`)
- Событий добавления (`*ADD`) — для создания начального состояния

**Не выполняется для:**
- Событий удаления (`*DELETE`)

---

## Структура директорий

### logs/state/

**Назначение:** хранение снимков состояния сущностей

**Формат файлов:** `{entityType}_{entityId}.json`

**Примеры:**
```
logs/state/
├── deal_123.json
├── deal_456.json
├── task_789.json
└── lead_111.json
```

### logs/field-changes/

**Назначение:** хранение изменений полей

**Формат файлов:** `{entityType}_{entityId}_{timestamp}.json`

**Примеры:**
```
logs/field-changes/
├── deal_123_20260126_120500.json
├── deal_123_20260126_121000.json
├── task_789_20260126_120800.json
└── field-changes.log
```

**Файл `field-changes.log`:**
- Общий лог всех изменений
- Формат: line-based JSON
- Одна строка = одно изменение

---

## Примеры использования

### Пример 1: Изменение стадии сделки

**Событие 1 (ONCRMDEALADD):**
```json
{
  "savedAt": "2026-01-26T12:00:00+03:00",
  "entityType": "deal",
  "entityId": "123",
  "data": {
    "ID": "123",
    "TITLE": "Deal title",
    "STAGE_ID": "NEW",
    "OPPORTUNITY": "10000"
  }
}
```

**Событие 2 (ONCRMDEALUPDATE):**
```json
{
  "savedAt": "2026-01-26T12:05:00+03:00",
  "entityType": "deal",
  "entityId": "123",
  "data": {
    "ID": "123",
    "TITLE": "Deal title",
    "STAGE_ID": "WON",
    "OPPORTUNITY": "15000"
  }
}
```

**Файл изменений:**
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

### Пример 2: Добавление нового поля

**Событие 1:**
```json
{
  "data": {
    "ID": "123",
    "TITLE": "Deal title"
  }
}
```

**Событие 2:**
```json
{
  "data": {
    "ID": "123",
    "TITLE": "Deal title",
    "NEW_FIELD": "value"
  }
}
```

**Изменения:**
```json
{
  "NEW_FIELD": {
    "old": null,
    "new": "value"
  }
}
```

---

## Очистка старых состояний

### Рекомендации

- **Состояния:** можно удалять состояния сущностей, которые больше не обновляются (например, удалённые сущности)
- **Изменения:** рекомендуется хранить для анализа (минимум 30 дней)

**Пример скрипта очистки:**
```bash
#!/bin/bash
# Удаление состояний старше 90 дней
find /var/www/.../outgoing-webhook/logs/state/ -name "*.json" -mtime +90 -delete

# Удаление изменений старше 30 дней
find /var/www/.../outgoing-webhook/logs/field-changes/ -name "*.json" -mtime +30 -delete
```

---

## Производительность

### Влияние на обработку

- **Время сравнения:** 10-50 мс (зависит от размера данных)
- **Время записи:** 20-100 мс (зависит от размера данных и количества изменений)

### Оптимизация

1. **Сравнение только изменённых полей:**
   - Сравниваются только поля, присутствующие в обоих состояниях
   - Новые и удалённые поля обрабатываются отдельно

2. **Рекурсивная сортировка:**
   - Выполняется только при сравнении
   - Результат не кешируется

---

## Связанные документы

- `07-enrichment-services.md` — сервисы обогащения (StateStorage, ValueComparator)
- `11-enrichment-detailed.md` — детальное описание обогащения
- `08-queue-services.md` — сервисы очереди (использование в QueueRunner)

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием StateStorage и отслеживания изменений.
