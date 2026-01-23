 # Исходящие события Bitrix24 (outgoing webhook)
 
 Дата создания: 2026-01-23 18:38 (UTC+03:00, Брест)
 
 ## Назначение
 Раздел описывает, как работает модуль исходящих событий Bitrix24 в REST‑приложении:
 приём событий, очередь, обогащение, расшифровка payload и запуск логики Activity First.
 
 ## Где находится модуль
 - Точка входа: `outgoing-webhook/index.php`
 - Публичная точка: `public/outgoing-webhook/index.php`
 - Очередь и обработчик: `outgoing-webhook/tools/process-queue.php`
 - Общие функции: `outgoing-webhook/bootstrap.php`
 - Activity First: `outgoing-webhook/activity/first/conditions.php`
 
 ## Состав раздела
 - `01-how-it-works.md` — общий поток обработки события.
 - `02-registered-events.md` — список регистрируемых событий и карта обогащения.
 - `03-payload-decoding.md` — схема payload и расшифровка (с обезличенными примерами).
 - `04-activity-first.md` — логика Activity First «под капотом».
- `bootstrap/README.md` — разбор `outgoing-webhook/bootstrap.php` по разделам.
 
 ## Связанные документы
 - `DOCS/TASKS/TASK-014-02-endpoint.md` — приём событий.
 - `DOCS/TASKS/TASK-014-05-enrichment.md` — обогащение данных.
 - `DOCS/TASKS/TASK-014-06-payload-schemas.md` — схемы файлов.
 - `DOCS/TASKS/TASK-014-07-event-registry.md` — реестр событий.
 - `DOCS/LOGS-Managment/outgoing-webhook-logs.md` — структура логов.
 
 ## Изменения
 - 2026-01-23 18:38 (UTC+03:00, Брест): создан раздел `OUTGOING-EVENTS`.
