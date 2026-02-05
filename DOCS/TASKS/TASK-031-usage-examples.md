# TASK-031: Примеры использования new_task_details

**Дата создания:** 2026-02-05 18:00 (UTC+3, Брест)  
**Статус:** Документация  
**Связанная задача:** TASK-031

## Обзор

Таблица `new_task_details` хранит полные снимки новых задач (событие `ONTASKADD`) с двумя типами данных:
- `raw_payload` — полный JSON ответ от Bitrix24 REST API (`tasks.task.get`)
- `extracted` — извлечённые ключевые поля в структурированном виде

## Структура данных

### Таблица `new_task_details`

```sql
CREATE TABLE new_task_details (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    task_id TEXT NOT NULL,
    raw_payload TEXT NOT NULL,  -- Полный JSON ответ REST
    extracted TEXT NOT NULL,     -- Ключевые поля (JSON)
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
```

### Структура `extracted` JSON

```json
{
    "requestId": "e9b15f1f5563e5ca48e97b9ac362b102",
    "eventType": "ONTASKADD",
    "taskId": "1479",
    "createdAt": "2026-02-05T17:35:35+03:00",
    "rawTruncated": false,
    "id": "1479",
    "title": "Название задачи",
    "description": "Описание задачи",
    "status": "2",
    "priority": "0",
    "createdBy": "1619",
    "responsibleId": "1619",
    "accomplices": [],
    "auditors": [],
    "groupId": "0",
    "projectId": null,
    "stageId": "0",
    "tags": [],
    "checklist": [],
    "crmLinks": [],
    "files": [],
    "attachments": [],
    "dates": {
        "created": "2026-02-05T17:35:31+03:00",
        "deadline": "2026-02-10T17:30:00+03:00",
        "startDatePlan": null,
        "endDatePlan": null,
        "closedDate": null,
        "changedDate": "2026-02-05T17:35:34+03:00"
    },
    "time": {
        "timeSpent": null,
        "timeEstimate": "0"
    },
    "uf": {}
}
```

### Структура `raw_payload` JSON

```json
{
    "result": {
        "task": {
            "id": "1479",
            "title": "Название задачи",
            "description": "",
            "status": "2",
            "priority": "0",
            "createdBy": "1619",
            "responsibleId": "1619",
            "deadline": "2026-02-10T17:30:00+03:00",
            ...
        }
    }
}
```

## Примеры использования

### 1. Получение репозитория

```php
$container = new ServiceContainer();
$repo = $container->get('newTaskDetailsRepository');
```

### 2. Найти запись по request_id

```php
$record = $repo->findByRequestId('e9b15f1f5563e5ca48e97b9ac362b102');
if ($record) {
    $extracted = json_decode($record['extracted'], true);
    echo "Заголовок: " . $extracted['title'];
}
```

### 3. Найти записи по task_id

```php
$records = $repo->findByTaskId('1479', 10);
foreach ($records as $record) {
    $extracted = json_decode($record['extracted'], true);
    echo "Заголовок: " . $extracted['title'] . "\n";
}
```

### 4. Найти записи по типу события

```php
$records = $repo->findByEventType('ONTASKADD', 100);
echo "Найдено записей: " . count($records);
```

### 5. Найти задачи по полю в extracted

```php
// Найти все задачи с ответственным ID = 1619
$tasks = $repo->findByExtractedField('responsibleId', '1619', '=', 50);

// Найти задачи со статусом = 2 (в работе)
$tasks = $repo->findByExtractedField('status', '2', '=', 50);

// Найти задачи с заголовком содержащим "важно" (LIKE)
$tasks = $repo->findByExtractedField('title', '%важно%', 'LIKE', 50);

// Найти задачи с приоритетом >= 2
$tasks = $repo->findByExtractedField('priority', '2', '>=', 50);
```

### 6. Получить значение поля из extracted

```php
// Получить заголовок задачи
$title = $repo->getExtractedFieldValue('1479', 'title');

// Получить ответственного
$responsibleId = $repo->getExtractedFieldValue('1479', 'responsibleId');

// Получить статус
$status = $repo->getExtractedFieldValue('1479', 'status');
```

### 7. Получить значение из raw_payload (JSONPath)

```php
// Получить заголовок из raw_payload
$title = $repo->getRawPayloadFieldValue('1479', '$.result.task.title');

// Получить ответственного
$responsibleId = $repo->getRawPayloadFieldValue('1479', '$.result.task.responsibleId');

// Получить статус
$status = $repo->getRawPayloadFieldValue('1479', '$.result.task.status');

// Получить дедлайн
$deadline = $repo->getRawPayloadFieldValue('1479', '$.result.task.deadline');
```

### 8. Комплексный запрос через SQL

```php
$db = $container->get('database');

// Найти задачи с дедлайном в ближайшие 7 дней
$sql = "SELECT * FROM new_task_details 
        WHERE json_extract(extracted, '$.dates.deadline') IS NOT NULL
        AND date(json_extract(extracted, '$.dates.deadline')) <= date('now', '+7 days')
        ORDER BY json_extract(extracted, '$.dates.deadline') ASC
        LIMIT 50";

$tasks = $db->queryAll($sql);

foreach ($tasks as $task) {
    $extracted = json_decode($task['extracted'], true);
    echo "Задача: {$extracted['title']}, Дедлайн: {$extracted['dates']['deadline']}\n";
}
```

### 9. Найти задачи с CRM-связями

```php
$db = $container->get('database');

// Найти задачи с CRM-связями (crmLinks не пустой массив)
$sql = "SELECT * FROM new_task_details 
        WHERE json_extract(extracted, '$.crmLinks') != '[]'
        ORDER BY created_at DESC
        LIMIT 50";

$tasks = $db->queryAll($sql);

foreach ($tasks as $task) {
    $extracted = json_decode($task['extracted'], true);
    echo "Задача: {$extracted['title']}, CRM-связи: " . count($extracted['crmLinks']) . "\n";
}
```

### 10. Найти задачи с файлами

```php
$db = $container->get('database');

// Найти задачи с файлами
$sql = "SELECT * FROM new_task_details 
        WHERE json_extract(extracted, '$.files') != '[]'
        ORDER BY created_at DESC
        LIMIT 50";

$tasks = $db->queryAll($sql);
```

## Доступные поля в extracted

### Основные поля
- `id` — ID задачи
- `title` — Заголовок задачи
- `description` — Описание задачи
- `status` — Статус задачи
- `priority` — Приоритет (0-3)
- `createdBy` — ID создателя
- `responsibleId` — ID ответственного
- `groupId` — ID группы/проекта
- `projectId` — ID проекта
- `stageId` — ID стадии канбана

### Массивы
- `accomplices` — Соисполнители (массив ID)
- `auditors` — Наблюдатели (массив ID)
- `tags` — Теги (массив)
- `checklist` — Чеклист (массив)
- `crmLinks` — CRM-связи (массив)
- `files` — Файлы (массив)
- `attachments` — Вложения (массив)

### Даты (объект `dates`)
- `created` — Дата создания
- `deadline` — Дедлайн
- `startDatePlan` — Плановая дата начала
- `endDatePlan` — Плановая дата окончания
- `closedDate` — Дата закрытия
- `changedDate` — Дата изменения

### Время (объект `time`)
- `timeSpent` — Затраченное время
- `timeEstimate` — Плановое время

### Пользовательские поля (объект `uf`)
- Все поля начинающиеся с `UF_` (кастомные поля Bitrix24)

## Инструменты для тестирования

### Тестовый скрипт

```bash
# Проверить работу репозитория
php outgoing-webhook/tools/test-new-task-details.php

# Детальный просмотр структуры данных задачи
php outgoing-webhook/tools/inspect-task-details.php [task_id]
```

## Примечания

1. **JSONPath в SQLite**: SQLite поддерживает функции `json_extract()` для работы с JSON. Путь к полю указывается в формате `$.field` или `$.nested.field`.

2. **Производительность**: Для частых запросов по полям в `extracted` рекомендуется создать индексы или использовать материализованные представления.

3. **Размер данных**: `raw_payload` может быть большим (до 800KB по умолчанию). Для больших payload используется обрезка (см. `NEW_TASK_DETAILS_MAX_BYTES` в конфиге).

4. **Типы данных**: Все значения в `extracted` хранятся как строки или массивы. Для числовых сравнений используйте приведение типов в SQL.

## История изменений

- 2026-02-05 18:00 (UTC+3, Брест): Создана документация с примерами использования
