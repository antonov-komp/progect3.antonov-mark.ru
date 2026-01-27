-- SQLite схема БД для хранения событий исходящих вебхуков
-- Дата создания: 2026-01-27 (UTC+3, Брест)
-- Версия: 1.0

-- Включение WAL режима для лучшей производительности
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;
PRAGMA synchronous = NORMAL;

-- Таблица событий
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL UNIQUE,
    event_type TEXT NOT NULL,
    entity_type TEXT,
    entity_id TEXT,
    received_at TEXT NOT NULL,
    ip TEXT,
    token_source TEXT,
    event_handler_id TEXT,
    member_id TEXT,
    payload TEXT NOT NULL, -- JSON
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Индексы для таблицы events
CREATE INDEX IF NOT EXISTS idx_events_request_id ON events(request_id);
CREATE INDEX IF NOT EXISTS idx_events_event_type ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_entity_type ON events(entity_type);
CREATE INDEX IF NOT EXISTS idx_events_entity_id ON events(entity_id);
CREATE INDEX IF NOT EXISTS idx_events_received_at ON events(received_at);
CREATE INDEX IF NOT EXISTS idx_events_entity_type_id ON events(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_events_event_type_received_at ON events(event_type, received_at);

-- Таблица деталей задач
CREATE TABLE IF NOT EXISTS task_details (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    task_id TEXT NOT NULL,
    details TEXT NOT NULL, -- JSON
    formatted_details TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (request_id) REFERENCES events(request_id) ON DELETE CASCADE
);

-- Индексы для таблицы task_details
CREATE INDEX IF NOT EXISTS idx_task_details_request_id ON task_details(request_id);
CREATE INDEX IF NOT EXISTS idx_task_details_event_type ON task_details(event_type);
CREATE INDEX IF NOT EXISTS idx_task_details_task_id ON task_details(task_id);
CREATE INDEX IF NOT EXISTS idx_task_details_event_type_task_id ON task_details(event_type, task_id);

-- Таблица деталей комментариев
CREATE TABLE IF NOT EXISTS comment_details (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    task_id TEXT NOT NULL,
    comment_id TEXT NOT NULL,
    details TEXT NOT NULL, -- JSON
    formatted_details TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (request_id) REFERENCES events(request_id) ON DELETE CASCADE
);

-- Индексы для таблицы comment_details
CREATE INDEX IF NOT EXISTS idx_comment_details_request_id ON comment_details(request_id);
CREATE INDEX IF NOT EXISTS idx_comment_details_event_type ON comment_details(event_type);
CREATE INDEX IF NOT EXISTS idx_comment_details_task_id ON comment_details(task_id);
CREATE INDEX IF NOT EXISTS idx_comment_details_comment_id ON comment_details(comment_id);
CREATE INDEX IF NOT EXISTS idx_comment_details_event_type_task_id ON comment_details(event_type, task_id);

-- Таблица обогащенных данных
CREATE TABLE IF NOT EXISTS enriched_data (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    enriched_at TEXT NOT NULL,
    source_method TEXT,
    response_time_ms INTEGER,
    data TEXT NOT NULL, -- JSON
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (request_id) REFERENCES events(request_id) ON DELETE CASCADE
);

-- Индексы для таблицы enriched_data
CREATE INDEX IF NOT EXISTS idx_enriched_data_request_id ON enriched_data(request_id);
CREATE INDEX IF NOT EXISTS idx_enriched_data_event_type ON enriched_data(event_type);
CREATE INDEX IF NOT EXISTS idx_enriched_data_entity_type ON enriched_data(entity_type);
CREATE INDEX IF NOT EXISTS idx_enriched_data_entity_id ON enriched_data(entity_id);
CREATE INDEX IF NOT EXISTS idx_enriched_data_entity_type_id ON enriched_data(entity_type, entity_id);

-- Таблица очереди обработки
CREATE TABLE IF NOT EXISTS queue_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL UNIQUE,
    event_type TEXT NOT NULL,
    entity_type TEXT,
    entity_id TEXT,
    status TEXT NOT NULL DEFAULT 'pending', -- pending, processing, done, failed
    attempt INTEGER NOT NULL DEFAULT 0,
    priority TEXT NOT NULL DEFAULT 'normal',
    source TEXT NOT NULL DEFAULT 'outgoing-webhook',
    token_source TEXT,
    event_handler_id TEXT,
    member_id TEXT,
    payload TEXT NOT NULL, -- JSON
    error_message TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    processed_at TEXT,
    FOREIGN KEY (request_id) REFERENCES events(request_id) ON DELETE CASCADE
);

-- Индексы для таблицы queue_jobs
CREATE INDEX IF NOT EXISTS idx_queue_jobs_request_id ON queue_jobs(request_id);
CREATE INDEX IF NOT EXISTS idx_queue_jobs_status ON queue_jobs(status);
CREATE INDEX IF NOT EXISTS idx_queue_jobs_event_type ON queue_jobs(event_type);
CREATE INDEX IF NOT EXISTS idx_queue_jobs_entity_type ON queue_jobs(entity_type);
CREATE INDEX IF NOT EXISTS idx_queue_jobs_entity_id ON queue_jobs(entity_id);
CREATE INDEX IF NOT EXISTS idx_queue_jobs_status_created_at ON queue_jobs(status, created_at);
CREATE INDEX IF NOT EXISTS idx_queue_jobs_status_priority ON queue_jobs(status, priority);

-- Таблица состояний сущностей
CREATE TABLE IF NOT EXISTS entity_states (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    state TEXT NOT NULL, -- JSON
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(entity_type, entity_id)
);

-- Индексы для таблицы entity_states
CREATE INDEX IF NOT EXISTS idx_entity_states_entity_type ON entity_states(entity_type);
CREATE INDEX IF NOT EXISTS idx_entity_states_entity_id ON entity_states(entity_id);
CREATE INDEX IF NOT EXISTS idx_entity_states_entity_type_id ON entity_states(entity_type, entity_id);

-- Таблица метрик ActivityFirst
CREATE TABLE IF NOT EXISTS activity_first_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    task_id TEXT NOT NULL,
    logged_at TEXT NOT NULL,
    sync BOOLEAN NOT NULL DEFAULT 0,
    duration_ms INTEGER,
    success BOOLEAN NOT NULL DEFAULT 0,
    files_count INTEGER DEFAULT 0,
    deals_count INTEGER DEFAULT 0,
    rate_limit_hit BOOLEAN NOT NULL DEFAULT 0,
    error TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (request_id) REFERENCES events(request_id) ON DELETE CASCADE
);

-- Индексы для таблицы activity_first_metrics
CREATE INDEX IF NOT EXISTS idx_activity_first_metrics_request_id ON activity_first_metrics(request_id);
CREATE INDEX IF NOT EXISTS idx_activity_first_metrics_task_id ON activity_first_metrics(task_id);
CREATE INDEX IF NOT EXISTS idx_activity_first_metrics_logged_at ON activity_first_metrics(logged_at);
CREATE INDEX IF NOT EXISTS idx_activity_first_metrics_success ON activity_first_metrics(success);
