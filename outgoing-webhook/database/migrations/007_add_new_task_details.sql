-- Миграция 007: таблица полного снимка новой задачи (ONTASKADD)
CREATE TABLE IF NOT EXISTS new_task_details (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    task_id TEXT NOT NULL,
    raw_payload TEXT NOT NULL, -- Полный ответ REST (JSON)
    extracted TEXT NOT NULL, -- Ключевые поля (JSON)
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_new_task_details_request_id ON new_task_details(request_id);
CREATE INDEX IF NOT EXISTS idx_new_task_details_event_type ON new_task_details(event_type);
CREATE INDEX IF NOT EXISTS idx_new_task_details_task_id ON new_task_details(task_id);
CREATE INDEX IF NOT EXISTS idx_new_task_details_event_task ON new_task_details(event_type, task_id);
