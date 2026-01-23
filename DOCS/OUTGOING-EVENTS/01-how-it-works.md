 # Как это работает: поток исходящих событий
 
 Дата создания: 2026-01-23 18:38 (UTC+03:00, Брест)
 
 ## Общая схема
 1. Bitrix24 отправляет POST‑событие на `outgoing-webhook/index.php`.
 2. Эндпоинт валидирует `token`, нормализует `eventType`, пишет логи и кладёт задание в очередь.
 3. Фоновый обработчик очереди (`tools/process-queue.php`) обогащает событие через REST API.
 4. Результат сохраняется в `enriched.json` и используется для дальнейшей логики (в т.ч. Activity First).
 
 ## Шаг 1. Приём события
 Файл: `outgoing-webhook/index.php`
 
 - Принимается только `POST`.
 - Токен берётся из тела запроса, валидируется и маскируется при записи.
 - `eventType` нормализуется к виду `ONCRMDEALADD` и т.п.
 - Создаются файлы:
   - `logs/<EVENT>/raw.json`
   - `logs/<EVENT>/event.log`
 - Создаётся задание в `queue/pending/`.
 
 Подробности: `DOCS/TASKS/TASK-014-02-endpoint.md`.
 
 ## Шаг 2. Очередь и обработка
 Файл: `outgoing-webhook/tools/process-queue.php`
 
 - Из задания извлекается `entityId` и `entityType`.
 - Определяется REST‑метод обогащения по карте `event → method`.
 - Выполняется REST‑вызов Bitrix24 и сохраняется `enriched.json`.
 - Подтягиваются справочники (воронки, стадии) при необходимости.
 - Для задач и комментариев выполняется дополнительное обогащение деталей.
 
 Подробности: `DOCS/TASKS/TASK-014-05-enrichment.md`.
 
 ## Шаг 3. Формирование Activity (первый слой)
 Файл: `outgoing-webhook/bootstrap.php`
 
 - Для событий задач/комментариев собираются детали (сообщение, файлы, CRM‑связи).
 - Выполняется проверка условий Activity First.
 - Результат сохраняется в поле `activityFirst` внутри деталей.
 
 Подробности: `DOCS/OUTGOING-EVENTS/04-activity-first.md`.
 
## Текущий проект: детали по задачам и комментариям
Ниже описано фактическое поведение для задач и комментариев в текущем проекте.

### 1) Новая задача (ONTASKADD)
Файл: `outgoing-webhook/index.php`

- Событие принимается и попадает в очередь как обычно.
- Дополнительно сразу выполняется REST‑вызов `tasks.task.get`.
- Из ответа собираются «детали задачи» и пишутся в:
  - `outgoing-webhook/logs/ONTASKADD/task-details.log`

Детали задачи — это текстовая строка с ключевыми полями (название, постановщик,
проект, сроки, плановые даты). Формат формируется функцией
`outgoingWebhookFormatTaskDetailsRu()`.

### 2) Обновление задачи (ONTASKUPDATE)
Логика совпадает с ONTASKADD:

- после приёма события выполняется `tasks.task.get`;
- формируются детали задачи;
- запись добавляется в:
  - `outgoing-webhook/logs/ONTASKUPDATE/task-details.log`.

### 3) Комментарий в задаче (ONTASKCOMMENTADD)
Комментарии приходят как события задач, но «под капотом» это сообщения чата.

Файл: `outgoing-webhook/index.php`

Основной путь:
- извлекается `commentId`;
- выполняется `task.commentitem.get` (если не найдено — fallback на `task.commentitem.getlist`);
- при успехе собираются «детали комментария» и пишутся в:
  - `outgoing-webhook/logs/ONTASKCOMMENTADD/comment-details.log`.

Если комментарий не найден по `commentId`, используется путь через чат:
- из задачи подтягивается `chatId`;
- выполняется `im.dialog.messages.get`;
- сообщение ищется по `messageId`;
- формируются детали комментария из данных чата;
- запись добавляется в `comment-details.log`.

Дополнительно:
- при наличии данных задачи CRM‑связи (`UF_CRM_TASK`) нормализуются;
- результат проверки Activity First записывается в поле `activityFirst`.

 ## Структура логов и файлов
 - Логи событий: `outgoing-webhook/logs/<EVENT>/`
   - `raw.json` — сырой payload
   - `event.log` — строковый журнал
   - `enriched.json` — обогащённые данные
 - Очередь:
   - `outgoing-webhook/queue/pending/`
   - `outgoing-webhook/queue/processing/`
   - `outgoing-webhook/queue/done/`
   - `outgoing-webhook/queue/failed/`
 
 Подробности: `DOCS/TASKS/TASK-014-06-payload-schemas.md` и `DOCS/LOGS-Managment/outgoing-webhook-logs.md`.
 
 ## Изменения
 - 2026-01-23 18:38 (UTC+03:00, Брест): создан документ с общим потоком.
- 2026-01-23 18:38 (UTC+03:00, Брест): добавлены детали по задачам/комментариям проекта.
