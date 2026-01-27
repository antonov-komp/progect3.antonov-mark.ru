# Структура БД для исходящих вебхуков (SQLite)

Дата: 2026-01-27 (UTC+3, Брест)

## Статус
SQLite база данных используется для хранения событий исходящих вебхуков модуля `outgoing-webhook/`.

## Назначение
Переход с файловой системы на SQLite для улучшения производительности, масштабируемости и функциональности модуля обработки событий Bitrix24.

## Расположение
- Файл БД: `outgoing-webhook/database/events.db`
- Схема: `outgoing-webhook/database/schema.sqlite.sql`

## Таблицы

### Таблица `events`

Назначение: Хранение основных событий исходящих вебхуков Bitrix24.

#### Столбцы
1. `id`
   - Тип: INTEGER PRIMARY KEY AUTOINCREMENT
   - Описание: Уникальный идентификатор события
   - Пример: 1

2. `request_id`
   - Тип: TEXT NOT NULL UNIQUE
   - Описание: Уникальный идентификатор запроса (генерируется при получении события)
   - Пример: "req_20260127_123456_abc123"

3. `event_type`
   - Тип: TEXT NOT NULL
   - Описание: Тип события (например, ONTASKCOMMENTADD, ONTASKDELETE)
   - Пример: "ONTASKCOMMENTADD"

4. `entity_type`
   - Тип: TEXT
   - Описание: Тип сущности (task, deal, lead и т.д.)
   - Пример: "task"

5. `entity_id`
   - Тип: TEXT
   - Описание: ID сущности
   - Пример: "123"

6. `received_at`
   - Тип: TEXT NOT NULL
   - Описание: Время получения события
   - Пример: "2026-01-27 12:34:56"

7. `ip`
   - Тип: TEXT
   - Описание: IP адрес отправителя
   - Пример: "192.168.1.1"

8. `token_source`
   - Тип: TEXT
   - Описание: Источник токена (webhook, api и т.д.)
   - Пример: "webhook"

9. `event_handler_id`
   - Тип: TEXT
   - Описание: ID обработчика события из Bitrix24
   - Пример: "handler_123"

10. `member_id`
    - Тип: TEXT
    - Описание: ID участника портала Bitrix24
    - Пример: "member_456"

11. `payload`
    - Тип: TEXT NOT NULL (JSON)
    - Описание: Полный payload события (маскированный)
    - Пример: '{"event":"ONTASKCOMMENTADD","data":{...}}'

12. `created_at`
    - Тип: TEXT NOT NULL DEFAULT (datetime('now'))
    - Описание: Время создания записи
    - Пример: "2026-01-27 12:34:56"

#### Связи и индексы
- PRIMARY KEY: `id`
- UNIQUE: `request_id`
- INDEX: `idx_events_request_id` на `request_id`
- INDEX: `idx_events_event_type` на `event_type`
- INDEX: `idx_events_entity_type` на `entity_type`
- INDEX: `idx_events_entity_id` на `entity_id`
- INDEX: `idx_events_received_at` на `received_at`
- INDEX: `idx_events_entity_type_id` на `(entity_type, entity_id)`
- INDEX: `idx_events_event_type_received_at` на `(event_type, received_at)`

#### Примеры данных
```sql
INSERT INTO events (
    request_id, event_type, entity_type, entity_id,
    received_at, ip, token_source, event_handler_id, member_id,
    payload, created_at
) VALUES (
    'req_20260127_123456_abc123',
    'ONTASKCOMMENTADD',
    'task',
    '123',
    '2026-01-27 12:34:56',
    '192.168.1.1',
    'webhook',
    'handler_123',
    'member_456',
    '{"event":"ONTASKCOMMENTADD","data":{...}}',
    '2026-01-27 12:34:56'
);
```

---

### Таблица `task_details`

Назначение: Хранение деталей задач, извлеченных из обогащенных данных.

#### Столбцы
1. `id` - INTEGER PRIMARY KEY AUTOINCREMENT
2. `request_id` - TEXT NOT NULL (FK → events.request_id)
3. `event_type` - TEXT NOT NULL
4. `task_id` - TEXT NOT NULL
5. `details` - TEXT NOT NULL (JSON)
6. `formatted_details` - TEXT
7. `created_at` - TEXT NOT NULL DEFAULT (datetime('now'))

#### Индексы
- INDEX: `idx_task_details_request_id` на `request_id`
- INDEX: `idx_task_details_event_type` на `event_type`
- INDEX: `idx_task_details_task_id` на `task_id`
- INDEX: `idx_task_details_event_type_task_id` на `(event_type, task_id)`
- FOREIGN KEY: `request_id` → `events(request_id)` ON DELETE CASCADE

---

### Таблица `comment_details`

Назначение: Хранение деталей комментариев к задачам.

#### Столбцы
1. `id` - INTEGER PRIMARY KEY AUTOINCREMENT
2. `request_id` - TEXT NOT NULL (FK → events.request_id)
3. `event_type` - TEXT NOT NULL
4. `task_id` - TEXT NOT NULL
5. `comment_id` - TEXT NOT NULL
6. `details` - TEXT NOT NULL (JSON)
7. `formatted_details` - TEXT
8. `created_at` - TEXT NOT NULL DEFAULT (datetime('now'))

#### Индексы
- INDEX: `idx_comment_details_request_id` на `request_id`
- INDEX: `idx_comment_details_event_type` на `event_type`
- INDEX: `idx_comment_details_task_id` на `task_id`
- INDEX: `idx_comment_details_comment_id` на `comment_id`
- INDEX: `idx_comment_details_event_type_task_id` на `(event_type, task_id)`
- FOREIGN KEY: `request_id` → `events(request_id)` ON DELETE CASCADE

---

### Таблица `queue_jobs`

Назначение: Очередь обработки событий для асинхронного обогащения данных.

#### Столбцы
1. `id` - INTEGER PRIMARY KEY AUTOINCREMENT
2. `request_id` - TEXT NOT NULL UNIQUE (FK → events.request_id)
3. `event_type` - TEXT NOT NULL
4. `entity_type` - TEXT
5. `entity_id` - TEXT
6. `status` - TEXT NOT NULL DEFAULT 'pending' (pending, processing, done, failed)
7. `attempt` - INTEGER NOT NULL DEFAULT 0
8. `priority` - TEXT NOT NULL DEFAULT 'normal'
9. `source` - TEXT NOT NULL DEFAULT 'outgoing-webhook'
10. `token_source` - TEXT
11. `event_handler_id` - TEXT
12. `member_id` - TEXT
13. `payload` - TEXT NOT NULL (JSON)
14. `error_message` - TEXT
15. `created_at` - TEXT NOT NULL DEFAULT (datetime('now'))
16. `updated_at` - TEXT NOT NULL DEFAULT (datetime('now'))
17. `processed_at` - TEXT

#### Индексы
- INDEX: `idx_queue_jobs_status` на `status`
- INDEX: `idx_queue_jobs_status_created_at` на `(status, created_at)`
- INDEX: `idx_queue_jobs_status_priority` на `(status, priority)`
- FOREIGN KEY: `request_id` → `events(request_id)` ON DELETE CASCADE

---

### Таблица `entity_states`

Назначение: Хранение состояний сущностей для отслеживания изменений.

#### Столбцы
1. `id` - INTEGER PRIMARY KEY AUTOINCREMENT
2. `entity_type` - TEXT NOT NULL
3. `entity_id` - TEXT NOT NULL
4. `state` - TEXT NOT NULL (JSON)
5. `updated_at` - TEXT NOT NULL DEFAULT (datetime('now'))

#### Индексы
- UNIQUE: `(entity_type, entity_id)`
- INDEX: `idx_entity_states_entity_type_id` на `(entity_type, entity_id)`

---

### Таблица `enriched_data`

Назначение: Хранение обогащенных данных событий (данные из REST API Bitrix24).

#### Столбцы
1. `id` - INTEGER PRIMARY KEY AUTOINCREMENT
2. `request_id` - TEXT NOT NULL (FK → events.request_id)
3. `event_type` - TEXT NOT NULL
4. `entity_type` - TEXT NOT NULL
5. `entity_id` - TEXT NOT NULL
6. `enriched_at` - TEXT NOT NULL
7. `source_method` - TEXT
8. `response_time_ms` - INTEGER
9. `data` - TEXT NOT NULL (JSON)
10. `created_at` - TEXT NOT NULL DEFAULT (datetime('now'))

#### Индексы
- INDEX: `idx_enriched_data_request_id` на `request_id`
- INDEX: `idx_enriched_data_entity_type_id` на `(entity_type, entity_id)`
- FOREIGN KEY: `request_id` → `events(request_id)` ON DELETE CASCADE

---

### Таблица `activity_first_metrics`

Назначение: Хранение метрик обработки ActivityFirst (синхронная обработка комментариев).

#### Столбцы
1. `id` - INTEGER PRIMARY KEY AUTOINCREMENT
2. `request_id` - TEXT NOT NULL (FK → events.request_id)
3. `task_id` - TEXT NOT NULL
4. `logged_at` - TEXT NOT NULL
5. `sync` - BOOLEAN NOT NULL DEFAULT 0
6. `duration_ms` - INTEGER
7. `success` - BOOLEAN NOT NULL DEFAULT 0
8. `files_count` - INTEGER DEFAULT 0
9. `deals_count` - INTEGER DEFAULT 0
10. `rate_limit_hit` - BOOLEAN NOT NULL DEFAULT 0
11. `error` - TEXT
12. `created_at` - TEXT NOT NULL DEFAULT (datetime('now'))

#### Индексы
- INDEX: `idx_activity_first_metrics_request_id` на `request_id`
- INDEX: `idx_activity_first_metrics_task_id` на `task_id`
- INDEX: `idx_activity_first_metrics_logged_at` на `logged_at`
- INDEX: `idx_activity_first_metrics_success` на `success`
- FOREIGN KEY: `request_id` → `events(request_id)` ON DELETE CASCADE

---

## Особенности SQLite

- **WAL режим**: Включен для лучшей производительности при параллельной записи
- **Внешние ключи**: Включены для обеспечения целостности данных
- **JSON поля**: Хранятся как TEXT, декодируются на уровне приложения
- **Транзакции**: Поддерживаются для атомарности операций

## Миграция данных

Существующие данные из файловой системы могут быть мигрированы в БД через скрипт `tools/migrate-to-sqlite.php` (будет создан в рамках TASK-027-06).

## Изменения

- 2026-01-27 (UTC+3, Брест): Создана схема БД SQLite для исходящих вебхуков. Реализованы таблицы для событий, очереди, деталей задач и комментариев, состояний сущностей и метрик.
