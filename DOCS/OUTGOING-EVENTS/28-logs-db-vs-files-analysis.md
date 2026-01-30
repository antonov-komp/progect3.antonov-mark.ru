# Анализ: БД vs файловые логи в модуле исходящих событий

**Дата:** 2026-01-30  
**Цель:** Сравнить, как реально работают логирование и хранение данных — на уровне БД и на уровне файловой структуры логов; выявить расхождения и дублирование.

---

## 1. Как выбирается режим (БД или файлы)

- **index.php** при `DATABASE_TYPE=sqlite` и доступных репозиториях использует **DatabaseEventProcessor** (события и очередь — в БД).
- При недоступности БД или `DATABASE_TYPE` не sqlite возможен **EventProcessor** (файловый), если включён **DATABASE_FALLBACK_TO_FILES**.
- Детали (задачи, комментарии, сделки) при любом процессоре пишутся **и в файлы, и в БД** (если репозитории доступны): TaskDetailsService, CommentWriter, DealDetailsService делают двойную запись.

Итог: разделение «логов» идёт по двум осям — **события/очередь** (БД или файлы) и **детали** (всегда файлы + при наличии БД ещё и таблицы).

---

## 2. Структура хранения по слоям

### 2.1 События (входящий webhook)

| Аспект | Файловая модель (EventProcessor) | Модель БД (DatabaseEventProcessor) |
|--------|-----------------------------------|-------------------------------------|
| **Где хранится** | `logs/<EVENT_TYPE>/raw.json` (перезапись последним событием) | Таблица **events** (одна строка на событие) |
| **Доп. лог** | `logs/<EVENT_TYPE>/event.log` — построчное дополнение (дата \| IP \| requestId \| event \| entityType \| entityId \| …) | Нет отдельного event.log; всё в **events** (payload, received_at, ip, token_source и т.д.) |
| **Разделение по типам** | Папка на тип: ONTASKADD, ONTASKCOMMENTADD, ONCRMDEALUPDATE и т.д. | Поле **event_type** в одной таблице **events** |
| **Детали внутри** | raw.json: requestId, eventType, receivedAt, ip, tokenSource, eventHandlerId, memberId, payload (JSON). event.log: текстовая строка. | Колонки: request_id, event_type, entity_type, entity_id, received_at, ip, token_source, event_handler_id, member_id, payload (JSON), created_at |

Вывод по событиям:

- При **БД**: в файлах **нет** raw.json и event.log по типам событий; история событий только в **events**.
- При **файлах**: один raw.json и накапливаемый event.log в каждой папке типа события; в БД записей событий нет.

---

### 2.2 Очередь заданий

| Аспект | Файловая модель | Модель БД |
|--------|-----------------|-----------|
| **Где хранится** | `queue/pending/*.json`, `queue/processing/`, `queue/done/`, `queue/failed/` | Таблица **queue_jobs** (status: pending, processing, done, failed) |
| **Кто пишет** | EventProcessor::createQueueItem() — файл в queue/pending | DatabaseEventProcessor::createQueueItem() — QueueRepository::createJob() |
| **Кто читает** | tools/process-queue.php через **QueueService** — файлы (glob по queue/pending/*.json) | При **DATABASE_TYPE=sqlite** и доступной БД: **DatabaseQueueService** и **DatabaseJobStateService** — задания из **queue_jobs**, статусы обновляются, зависшие возвращаются в pending. |

Итог (после TASK-029):

- При **DATABASE_TYPE=sqlite** и доступной БД: process-queue.php использует очередь из **queue_jobs** (DatabaseQueueService, DatabaseJobStateService). Задания обрабатываются тем же пайплайном, статусы обновляются в БД.
- При отключённой БД: process-queue работает с **queue/pending** и файловой очередью (обратная совместимость).

---

### 2.3 Детали: задачи, комментарии, сделки

Во всех трёх случаях логика одна: **сначала запись в файл, затем при наличии репозитория — запись в БД**.

#### Задачи (task)

| Хранение | Расположение | Формат |
|----------|--------------|--------|
| Файл | `logs/<EVENT_TYPE>/task-details.log` | Одна строка на запись: `Дата=... \| requestId=... \| Событие=... \| Задача=... \| Название=... \| Постановщик=... \| Проект=... \| Срок=... \| ПланСтарт=... \| ПланФиниш=...` |
| БД | Таблица **task_details** | request_id, event_type, task_id, details (JSON), formatted_details, created_at. Связь с events по request_id. |

Разделение по типам: в файлах — папка на каждый EVENT_TYPE (ONTASKADD, ONTASKUPDATE, ONTASKCOMMENTADD, ONTASKDELETE и т.д.); в БД — общая таблица, тип в **event_type**.

#### Комментарии (comment)

| Хранение | Расположение | Формат |
|----------|--------------|--------|
| Файл | `logs/<EVENT_TYPE>/comment-details.log` | Строка: Дата, requestId, Событие, Задача, Проект, CRM, КомментарийID, Автор, Тип, Создано, Текст, Файлы, ActivityFirst, ActivityType, Метод |
| БД | Таблица **comment_details** | request_id, event_type, task_id, comment_id, details (JSON), formatted_details, created_at |

Разделение по типам: в файлах — только для событий, начинающихся с ONTASK (одна папка ONTASKCOMMENTADD); в БД — все в одной таблице с полем event_type.

#### Сделки (deal)

| Хранение | Расположение | Формат |
|----------|--------------|--------|
| Файл | `logs/ONCRMDEALADD/deal-details.log`, `logs/ONCRMDEALUPDATE/deal-details.log` | Строка: Дата, requestId, Событие, Сделка, Название, Этап, Сумма, Валюта, Создана, Создатель |
| БД | Таблица **deal_details** | request_id, event_type, deal_id, details (JSON), details_resolved (JSON), formatted_details, + ключевые поля (stage_id, stage_title, category_id, assigned_by_id и т.д.), created_at |

Разделение: в файлах — две папки по типу события (ONCRMDEALADD, ONCRMDEALUPDATE); в БД — одна таблица, тип в event_type.

Итог по деталям:

- В файлах разделение логов — **по типу события** (папка = EVENT_TYPE), внутри — построчные логи и/или один общий файл на тип.
- В БД разделение — **по сущности** (task_details, comment_details, deal_details), тип события в колонке event_type; плюс связь с событием по request_id.

---

### 2.4 Обогащённые данные (enriched)

| Аспект | Файлы | БД |
|--------|-------|-----|
| **Файл** | QueueRunner пишет `logs/<EVENT_TYPE>/enriched.json` (один файл на тип, перезапись последним результатом обогащения). | — |
| **БД** | — | Таблица **enriched_data** (request_id, event_type, entity_type, entity_id, enriched_at, source_method, response_time_ms, data JSON). После TASK-029 при обработке очереди из БД QueueRunner при наличии EnrichedDataRepository пишет обогащённый результат в **enriched_data** (в дополнение к enriched.json по типу события). |

Вывод: при очереди из БД обогащённые данные пишутся и в enriched.json, и в **enriched_data**; при файловой очереди — только в enriched.json.

---

### 2.5 Activity First (метрики и факты)

| Аспект | Файлы | БД |
|--------|-------|-----|
| **Метрики (агрегат)** | `logs/activity-first-metrics.log` — построчно JSON (loggedAt, requestId, taskId, sync, durationMs, success, filesCount, dealsCount, rateLimitHit, activityType и т.д.). | Таблица **activity_first_metrics** — те же поля плюс file_name, file_size, result_full и др.; заполняется в bootstrap при синхронной обработке Activity. |
| **Факты (полный результат)** | `logs/activity-first.log` — построчно JSON (loggedAt, requestId, taskId, sync, dealIds, fileIds, taskAttach, dealUpdates, activityType, dealField и т.д.). | В **activity_first_metrics** поле **result_full** (JSON) дублирует по смыслу полный результат из activity-first.log. |

Разделение: в файлах — два лога (метрики и полный результат); в БД — одна таблица, где есть и метрики, и result_full.

---

### 2.6 Состояния и изменения полей

| Сущность | Файлы | БД |
|----------|-------|-----|
| Состояние сущности | `logs/state/<entity_type>_<entity_id>.json` (например deal_13177.json) | Таблица **entity_states** (entity_type, entity_id, state JSON, updated_at) — используется StateStorage при наличии репозитория. |
| Изменения полей | Нет отдельного файлового лога. | Таблица **entity_field_changes** (entity_type, entity_id, event_type, changed_at, changes JSON, changes_resolved JSON). |

Только БД даёт историю изменений полей; в файловой модели есть только снимки состояния в logs/state.

---

## 3. Сводная таблица: что где есть

| Данные | Файловая структура | Таблица/место в БД | Двойная запись (файл + БД) |
|--------|--------------------|---------------------|----------------------------|
| Входящее событие | raw.json, event.log по типу | events | Нет при БД (только БД); при файлах — только файлы |
| Очередь заданий | queue/pending, processing, done, failed | queue_jobs | При БД: только БД (process-queue читает queue_jobs); при файлах: только файлы |
| Детали задач | task-details.log по типу | task_details | Да |
| Детали комментариев | comment-details.log по типу | comment_details | Да |
| Детали сделок | deal-details.log по типу | deal_details | Да |
| Обогащённые данные | enriched.json по типу | enriched_data (при очереди из БД) | При очереди из БД: файл + БД; при файловой очереди: только файл |
| Метрики Activity First | activity-first-metrics.log | activity_first_metrics | Да |
| Полный результат Activity First | activity-first.log | activity_first_metrics.result_full | По смыслу да (файл + поле в БД) |
| Состояние сущности | state/*.json | entity_states | Да при наличии репозитория |
| Изменения полей | — | entity_field_changes | Только БД |

---

## 4. Разделение логов: файлы vs БД

### 4.1 По типам событий

- **Файлы:** разделение по папкам — одна папка на EVENT_TYPE (ONTASKADD, ONTASKCOMMENTADD, ONTASKUPDATE, ONTASKDELETE, ONCRMDEALADD, ONCRMDEALUPDATE). В папке — raw.json (или нет при БД), event.log (или нет при БД), task-details.log / comment-details.log / deal-details.log в зависимости от типа.
- **БД:** одно пространство (таблицы events, task_details, comment_details, deal_details), разделение по полю **event_type**.

### 4.2 По уровню детализации

- **Файлы:**  
  - «Сырое» событие: raw.json (при файловом процессоре).  
  - Краткая строка: event.log (при файловом процессоре).  
  - Детали: построчные логи в *-details.log (всегда, для задач/комментариев/сделок).  
  - Обогащение: один enriched.json на тип (перезапись).  
  - Activity First: два лога — метрики и полный результат.  
  - Состояние: один JSON-файл на сущность.
- **БД:**  
  - Событие: одна строка в events с полным payload.  
  - Детали: отдельные строки в task_details, comment_details, deal_details с details (JSON) и formatted_details.  
  - Обогащение: таблица enriched_data не используется.  
  - Activity First: одна таблица activity_first_metrics с метриками и result_full.  
  - Состояние и история: entity_states, entity_field_changes.

---

## 5. Рекомендации

1. **Очередь и БД:** Либо доработать обработку очереди так, чтобы при DATABASE_TYPE=sqlite использовался источник заданий из **queue_jobs** (например DatabaseQueueService + доработка JobStateService под БД), либо явно документировать, что при включённой БД очередь из БД не обрабатывается и QUEUE_ENABLED по сути не задействует БД-очередь.
2. **Таблица enriched_data:** Либо начать писать обогащённые данные в неё (из QueueRunner и при синхронной обработке), либо убрать таблицу из схемы, чтобы не создавать путаницу.
3. **Двойная запись деталей:** Текущая схема (файл + БД) даёт обратную совместимость и удобство просмотра логов; при желании можно ввести конфиг «писать детали только в БД» и отключить append в *-details.log.
4. **Единый справочник:** Имеет смысл держать один документ (например в DOCS/OUTGOING-EVENTS или DOCS/LOGS-Managment), который явно описывает: при каком режиме (БД/файлы) что пишется и куда (какие файлы и какие таблицы), и что process-queue.php при БД не обрабатывает очередь из БД.

---

## 6. Итог

- **События:** в режиме БД хранятся только в **events**; в режиме файлов — в raw.json и event.log по типам.
- **Очередь:** в режиме БД хранится и обрабатывается из **queue_jobs** (process-queue.php при DATABASE_TYPE=sqlite использует DatabaseQueueService и DatabaseJobStateService; статусы обновляются, зависшие задания восстанавливаются). При отключённой БД — только файловая очередь (queue/pending и т.д.).
- **Детали (задачи, комментарии, сделки):** всегда пишутся в файлы *-details.log и при доступной БД — в таблицы task_details, comment_details, deal_details; разделение в файлах по типу события, в БД — по таблице и event_type.
- **Обогащённые данные:** при очереди из БД — запись в enriched_data и в enriched.json по типу; при файловой очереди — только в enriched.json.
- **Activity First:** проверка «уже обработан» при БД опирается на наличие записи в **activity_first_metrics** (existsByRequestAndTask); при отсутствии БД — на файлы state/activity-first-processed. Метрики дублируются в файлы и в activity_first_metrics.
- **Состояния и изменения полей:** в файлах только state/*.json; в БД — entity_states и entity_field_changes.

Таким образом, модуль работает в гибридном режиме: при включённой БД события и очередь хранятся и обрабатываются из БД (queue_jobs); детали, метрики Activity First, при наличии — состояния пишутся и в файлы, и в БД; обогащённые данные при очереди из БД пишутся и в enriched_data, и в enriched.json.

---

### 6.1 Использование БД для событий и очереди (после TASK-029)

- **Источник истины:** при `DATABASE_TYPE=sqlite` и доступной БД события пишутся в **events**, задания очереди — в **queue_jobs**. Обработка очереди запускается тем же **tools/process-queue.php** и берёт задания из БД (pending → processing → done/failed).
- **Перенос старых данных:** уже накопленные в файлах данные (raw.json, event.log, queue/pending/*.json, *-details.log и т.д.) **не переносятся** в БД. Старые файлы остаются как архив; с момента включения БД новые события и очередь хранятся и обрабатываются через БД.
- **Проверка работы:** отправить событие → проверить запись в **queue_jobs** (status=pending) → вызвать process-queue.php (GET/POST с limit) → убедиться, что статус задания сменился на done (или failed), в **task_details** / **comment_details** появились записи при обработке ONTASK*.

---

## 7. Функционал только на файлах — в БД этого нет (актуально до TASK-029; частично закрыто)

Ниже перечислено всё, что на уровне функционала делается через **файловое хранилище** и при этом **не имеет реализации или аналога в БД**. Это не «дублирование», а именно отсутствие в БД.

| № | Функционал | Где реализовано (файлы) | В БД |
|---|------------|-------------------------|------|
| **1** | **Снимок сырого события по типу + построчный event-лог** | При файловом процессоре: `logs/<EVENT_TYPE>/raw.json`, `event.log`. При БД эти файлы не создаются — в БД есть только таблица events (история), но нет «последнего raw по типу» и нет отдельного текстового event.log. | Нет аналога «последний raw по типу»; нет отдельного event.log (есть только строки в events). |
| **2** | **Обработка очереди заданий** | При файловой очереди: process-queue читает из `queue/pending/*.json`. | **Закрыто TASK-029:** при DATABASE_TYPE=sqlite process-queue использует **queue_jobs** (DatabaseQueueService), тот же пайплайн. |
| **3** | **Управление состоянием очереди (pending → processing → done/failed)** | JobStateService перемещает файлы между каталогами. | **Закрыто TASK-029:** DatabaseJobStateService обновляет status в **queue_jobs**. |
| **4** | **Восстановление зависших заданий (recoverProcessing)** | JobStateService по таймауту возвращает файлы из processing в pending. | **Закрыто TASK-029:** DatabaseJobStateService вызывает QueueRepository::resetStaleProcessing(). |
| **5** | **Хранение обогащённых данных (enriched)** | QueueRunner пишет enriched.json. | **Закрыто TASK-029:** при очереди из БД QueueRunner пишет в **enriched_data** через EnrichedDataRepository. |
| **6** | **Маркер «Activity First уже обработан» для пары requestId+taskId** | Файлы state/activity-first-processed/{requestId}_{taskId}.json. | **Закрыто TASK-029:** при БД TaskDetailsService использует ActivityFirstMetricsRepository::existsByRequestAndTask(). |
| **7** | **Лог шагов обработки очереди (started/finished)** | QueueStepLogger пишет в `logs/queue-steps.log` (построчно JSON: step, requestId, eventType, entityId, status, error и т.д.). | Нет таблицы или записей в БД для шагов обработки очереди (нет аналога queue_steps или лога в queue_jobs). |
| **8** | **Логирование ошибок приложения** | ErrorService пишет в `logs/errors/error-YYYYMMDD.log` (построчно JSON: loggedAt, message, context). | Нет таблицы error_log или аналога в БД; ошибки только в файлах (и в error_log PHP). |
| **9** | **Кеш справочников (этапы, категории, поля сделок, пользователи)** | DictCacheService хранит JSON только в `logs/dicts/*.json` (например deal_stages.json, user_*.json). Чтение/запись — только файлы. | Нет таблиц в БД для кеша справочников; всё только в файловой структуре. |
| **10** | **Чтение состояния сущности по «пути»** | StateStorage даёт путь к файлу `state/<entity_type>_<entity_id>.json` и читает состояние из файла. При наличии репозитория состояние дублируется в **entity_states**, но логика «путь к снимку» и часть кода завязаны на файл. | В БД есть entity_states, но проверка «есть ли файл», getPath() и т.п. остаются файловыми; единого «источника истины» только в БД для состояния нет. |

### Кратко по пунктам

- **1** — при БД нет файлов raw.json/event.log по типам; в БД только таблица events (нет «последнего по типу» и отдельного лога).
- **2–4** — при DATABASE_TYPE=sqlite реализованы через DatabaseQueueService, DatabaseJobStateService и QueueRepository (setProcessing, resetStaleProcessing, updateStatus).
- **5** — при очереди из БД обогащённые данные пишутся в **enriched_data** (EnrichedDataRepository).
- **6** — при БД проверка «уже обработан» по записи в **activity_first_metrics** (existsByRequestAndTask).
- **7** — лог шагов очереди только в queue-steps.log; в БД нет.
- **8** — логи ошибок только в файлах errors/; в БД нет.
- **9** — кеш справочников только в logs/dicts/; в БД нет.
- **10** — работа со состоянием сущности частично дублируется в entity_states, но «файловый» контракт (путь, чтение из файла) никуда не делся; чисто БД-варианта «только БД» для состояния нет.

Итого: перечисленный функционал существует только на уровне файлового хранилища; в БД либо нет соответствующих таблиц/полей, либо таблицы есть (queue_jobs, enriched_data), но код их не использует для этого поведения.
