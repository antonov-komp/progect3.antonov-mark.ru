 # Исходящие события Bitrix24 (outgoing webhook)
 
 Дата создания: 2026-01-23 18:38 (UTC+03:00, Брест)
 
 ## Назначение
 Раздел описывает, как работает модуль исходящих событий Bitrix24 в REST‑приложении:
 приём событий, очередь, обогащение, расшифровка payload и запуск логики Activity First.
 
 ## Где находится модуль
 - Точка входа: `outgoing-webhook/index.php`
 - Публичная точка: `public/outgoing-webhook/index.php`
- Очередь и обработчик: `outgoing-webhook/tools/process-queue.php`
- CLI‑обёртка очереди (cron): `outgoing-webhook/tools/process-queue-cli.php`
- Shim‑функции: `outgoing-webhook/bootstrap.php`
- Сервисы очереди/обогащения: `outgoing-webhook/services/`
 - Activity First: `outgoing-webhook/activity/first/conditions.php`
 
## Состав раздела
- `01-how-it-works.md` — общий поток обработки события.
- `02-registered-events.md` — список регистрируемых событий и карта обогащения.
- `03-payload-decoding.md` — схема payload и расшифровка (с обезличенными примерами).
- `04-activity-first.md` — логика Activity First «под капотом».
- `05-services-architecture.md` — архитектура сервисного слоя модуля.
- `06-core-services.md` — детальное описание базовых сервисов (Config, Filesystem, Request, Access, Identity, Error, Logging, Rest, Dicts).
- `07-enrichment-services.md` — сервисы обогащения данных (EnrichmentService, EntityHandlers, StateStorage).
- `08-queue-services.md` — сервисы очереди (QueueService, QueueJob, QueueRunner, JobStateService).
- `09-task-services.md` — сервисы работы с задачами и комментариями (TaskDetails, TaskFiles, DealFiles, CommentDetails).
- `10-queue-detailed.md` — детальное описание очереди обработки событий.
- `11-enrichment-detailed.md` — детальное описание процесса обогащения данных.
- `13-activity-first-sync.md` — синхронная обработка ActivityFirst.
- `17-configuration.md` — все настройки модуля (переменные окружения, config.local.php).
- `18-error-handling.md` — обработка ошибок в модуле.
- `19-security.md` — механизмы безопасности модуля.
- `bootstrap/README.md` — разбор `outgoing-webhook/bootstrap.php` по разделам.
- `documentation-plan.md` — план дополнительной документации модуля (пробелы и приоритеты).
 
 ## Связанные документы
 - `DOCS/TASKS/TASK-014-02-endpoint.md` — приём событий.
 - `DOCS/TASKS/TASK-014-05-enrichment.md` — обогащение данных.
 - `DOCS/TASKS/TASK-014-06-payload-schemas.md` — схемы файлов.
 - `DOCS/TASKS/TASK-014-07-event-registry.md` — реестр событий.
 - `DOCS/LOGS-Managment/outgoing-webhook-logs.md` — структура логов.
 
## Изменения
- 2026-01-23 18:38 (UTC+03:00, Брест): создан раздел `OUTGOING-EVENTS`.
- 2026-01-23 20:10 (UTC+03:00, Брест): добавлена CLI‑обёртка и сервисы обработки очереди.
- 2026-01-23 22:45 (UTC+03:00, Брест): отражена shim‑модель bootstrap.
- 2026-01-26 (UTC+03:00, Брест): создан план дополнительной документации модуля.
- 2026-01-26 (UTC+03:00, Брест): созданы документы высокого и среднего приоритета:
  - `05-services-architecture.md` — архитектура сервисов
  - `06-core-services.md` — базовые сервисы
  - `07-enrichment-services.md` — сервисы обогащения
  - `08-queue-services.md` — сервисы очереди
  - `09-task-services.md` — сервисы задач
  - `10-queue-detailed.md` — детальное описание очереди
  - `11-enrichment-detailed.md` — детальное описание обогащения
  - `13-activity-first-sync.md` — синхронная обработка ActivityFirst
  - `17-configuration.md` — конфигурация модуля
  - `18-error-handling.md` — обработка ошибок
  - `19-security.md` — безопасность модуля