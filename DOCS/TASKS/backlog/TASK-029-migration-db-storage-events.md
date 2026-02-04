# TASK-029: Миграция на хранение событий в БД (без переноса старых данных)

**Дата создания:** 2026-01-30  
**Статус:** Новая  
**Приоритет:** Высокий  
**Исполнитель:** Backend PHP Developer (модуль исходящих вебхуков)  
**Связанный анализ:** [DOCS/OUTGOING-EVENTS/28-logs-db-vs-files-analysis.md](../OUTGOING-EVENTS/28-logs-db-vs-files-analysis.md)

---

## 1. Цель

Завершить переход модуля исходящих событий на использование **БД как основного хранилища** для событий и связанных с ними процессов. Уже накопленные в файлах данные **не переносим** в БД — миграция касается только логики и будущего хранения: с момента внедрения все новые события и связанный функционал работают через БД.

### Оглавление

| Раздел | Содержание |
|--------|-------------|
| §2 | Контекст, проблема, границы задачи |
| §3 | Требования к результату (обязательные и желательные) |
| §4 | Модули и компоненты (файлы, таблицы) |
| §5 | Зависимости |
| §6 | **Структурированный подход** — фазы 0–7, детали реализации, риски |
| §7 | Краткий список подзадач по фазам |
| §8 | Критерии приёмки |
| §9 | История правок |

---

## 2. Контекст

### 2.1 Текущее состояние

- **index.php** при `DATABASE_TYPE=sqlite` и доступной БД использует **DatabaseEventProcessor**: входящие события пишутся в таблицу **events**, задания очереди — в **queue_jobs**.
- Детали (задачи, комментарии, сделки) пишутся **и в файлы** (*-details.log), **и в БД** (task_details, comment_details, deal_details).
- Метрики Activity First пишутся и в файлы (activity-first.log, activity-first-metrics.log), и в **activity_first_metrics**.

### 2.2 Проблема

Часть функционала реализована **только на файловом хранилище**; в БД для неё либо нет таблиц, либо таблицы есть, но код их не использует. В результате при включённой БД:

- Задания из **queue_jobs** не обрабатываются: `process-queue.php` читает только из `queue/pending/*.json`.
- Нет обновления статусов очереди в БД (processing / done / failed).
- Нет восстановления зависших заданий (recoverProcessing) по записям в БД.
- Маркер «Activity First уже обработан» хранится только в файлах `state/activity-first-processed/`.
- Обогащённые данные пишутся только в enriched.json; таблица **enriched_data** не используется.

Полный перечень «функционал только в файлах — в БД этого нет» описан в **разделе 7** документа [28-logs-db-vs-files-analysis.md](../OUTGOING-EVENTS/28-logs-db-vs-files-analysis.md).

### 2.3 Что не входит в задачу

- **Перенос уже созданных событий/логов из файлов в БД не требуется.** Старые файлы остаются как архив/история; новые данные с момента перехода хранятся и обрабатываются через БД.
- Не требуется скрипт импорта raw.json / event.log / *-details.log в таблицы.
- Опционально: логи ошибок (errors/), кеш справочников (dicts/), лог шагов очереди (queue-steps.log) могут остаться файловыми — в рамках этой задачи фокус на «события + очередь + маркеры, для которых в БД уже есть или добавляется схема».

---

## 3. Требования к результату

### 3.1 Обязательно

1. **Обработка очереди из БД**  
   При `DATABASE_TYPE=sqlite` и включённой очереди (`QUEUE_ENABLED=true`) задания из таблицы **queue_jobs** со статусом `pending` должны обрабатываться тем же пайплайном (обогащение → детали → комментарии/сделки и т.д.). То есть процессор очереди должен уметь брать задания из БД, а не только из `queue/pending/*.json`.

2. **Управление статусами заданий в БД**  
   В процессе обработки статус записи в **queue_jobs** должен обновляться: `pending` → `processing` → `done` или `failed`. Должна быть реализована логика, эквивалентная JobStateService, но работающая с **queue_jobs** (обновление полей status, attempt, error_message, processed_at и т.д.).

3. **Восстановление зависших заданий (recoverProcessing) для БД**  
   Записи в **queue_jobs** со статусом `processing`, зависшие дольше заданного таймаута (например, как в JobStateService — 15 минут), должны возвращаться в `pending`, чтобы их можно было снова обработать.

4. **Единая точка запуска обработки очереди**  
   Один способ запуска обработки (например, `tools/process-queue.php` или отдельный скрипт/эндпоинт), который при наличии БД и настроек «очередь из БД» использует **queue_jobs**, а при отсутствии БД/настроек — по-прежнему файловую очередь (обратная совместимость).

5. **Маркер «Activity First уже обработан» в БД**  
   Проверка «уже обработан ли комментарий (requestId + taskId) для Activity First» должна опираться на БД, а не только на наличие файла в `state/activity-first-processed/`. Варианты: использовать существующую таблицу **activity_first_metrics** (наличие записи по request_id + task_id) или ввести отдельную небольшую таблицу/поля; при записи маркера — писать в БД (и при желании сохранять запись в файл для обратной совместимости).

6. **Документация**  
   В документации модуля (или в 28-logs-db-vs-files-analysis.md / отдельный миграционный гайд) явно зафиксировать: «для работы с событиями используется БД; старые файловые данные не переносятся; с момента включения БД новые события и очередь хранятся и обрабатываются из БД».

### 3.2 Желательно (в объёме задачи или отдельными подзадачами)

7. **Запись обогащённых данных в БД**  
   После обогащения сохранять результат в таблицу **enriched_data** (в дополнение или вместо перезаписи enriched.json по типу события — на усмотрение, с учётом объёма данных и частоты).

8. **Опция «детали только в БД»**  
   Конфигурационная опция (например, `DETAILS_ONLY_IN_DB`), при включении которой детали задач/комментариев/сделок не дописываются в файлы *-details.log, а только в таблицы task_details, comment_details, deal_details.

9. **Лог шагов обработки очереди в БД (опционально)**  
   При наличии таблицы или полей под лог шагов (started/finished по каждому заданию) — писать туда из QueueStepLogger при обработке очереди из БД; иначе оставить только файл queue-steps.log.

---

## 4. Модули и компоненты

### 4.1 Затрагиваемые/новые файлы

| Компонент | Файл(ы) | Изменения |
|-----------|---------|-----------|
| Обработка очереди | `outgoing-webhook/tools/process-queue.php` | Использовать при наличии БД — QueueRepository + сервис очереди, работающий с БД (DatabaseQueueService или аналог), и runner, обновляющий статусы в queue_jobs. |
| Состояние заданий очереди | `outgoing-webhook/services/Queue/JobStateService.php` или новый `DatabaseJobStateService.php` | Либо расширить JobStateService поддержкой БД (по конфигу/типу хранилища), либо ввести DatabaseJobStateService: markProcessing, markDone, markFailed, recoverProcessing — через обновление queue_jobs. |
| Список заданий | `outgoing-webhook/services/Queue/DatabaseQueueService.php` | Уже есть listPending; при использовании в process-queue обеспечить, что runner берёт задания отсюда при DATABASE_TYPE=sqlite. |
| Репозиторий очереди | `outgoing-webhook/services/Database/Repositories/QueueRepository.php` | При необходимости добавить методы: listPending(limit), updateStatus(id, status), updateProcessing(id, updated_at), resetStaleProcessing(timeoutSeconds). |
| Маркер Activity First | `outgoing-webhook/services/Task/TaskDetailsService.php` | markActivityFirstProcessed / isActivityFirstProcessed: при наличии репозитория/БД проверять и писать в БД (activity_first_metrics по request_id+task_id или отдельная таблица). |
| Обогащённые данные | `outgoing-webhook/services/Queue/QueueRunner.php` | После buildEnriched при работе с БД — запись в enriched_data через новый или существующий EnrichedDataRepository (если репозиторий есть в контейнере). |
| Контейнер сервисов | `outgoing-webhook/services/Container/ServiceContainer.php` | Зарегистрировать при необходимости: DatabaseQueueService как источник очереди при БД, DatabaseJobStateService (или фабрику JobState с выбором файл/БД), EnrichedDataRepository. |
| Конфигурация | `outgoing-webhook/config.local.php` или аналог | Опция QUEUE_USE_DATABASE (или использовать уже существующий DATABASE_TYPE) для выбора источника очереди; опционально DETAILS_ONLY_IN_DB. |
| Документация | `DOCS/OUTGOING-EVENTS/28-logs-db-vs-files-analysis.md` или новый миграционный гайд | Раздел «Использование БД для событий»: что считается источником истины, что не переносится из файлов, как запускать обработку очереди при БД. |

### 4.2 Существующие таблицы (без изменения схемы, только использование)

- **events** — уже пишется DatabaseEventProcessor.
- **queue_jobs** — уже пишется при создании задания; нужно добавить чтение pending и обновление status/attempt/error_message/processed_at.
- **activity_first_metrics** — уже пишется; использовать для проверки «уже обработан» по (request_id, task_id) или ввести небольшую таблицу activity_first_processed(request_id, task_id, created_at).
- **enriched_data** — схема есть; код записи добавить в QueueRunner (или в отдельный сервис, вызываемый из runner).

---

## 5. Зависимости

- TASK-027 (SQLite, схема, репозитории) — частично выполнено: схема и репозитории events, queue_jobs, task_details, comment_details, deal_details, activity_first_metrics есть; DatabaseEventProcessor и запись в queue_jobs при создании события работают.
- Анализ [28-logs-db-vs-files-analysis.md](../OUTGOING-EVENTS/28-logs-db-vs-files-analysis.md) — источник перечня «только файлы» (раздел 7).
- Текущий `process-queue.php` и QueueRunner, JobStateService, TaskDetailsService — сохранить обратную совместимость при работе без БД или с файловой очередью.

---

## 6. Структурированный подход к выполнению

Задачу выполнять **по фазам**: сначала инфраструктура очереди в БД (репозиторий + JobState), затем точка входа process-queue и адаптация QueueRunner, затем маркер Activity First и опционально enriched_data. После каждой фазы — проверка критериев и при необходимости откат.

### 6.1 Фаза 0: Подготовка (чтение кода и контрактов)

- Прочитать `QueueRunner::run()` и убедиться, что понятны все вызовы: `$this->queue->listPending()`, `$this->jobState->markProcessing()`, `markDone()`, `markFailed()`, `recoverProcessing()`, а также использование `$job['requestId']`, `$job['eventType']`, `$job['payload']`, `$job['rawPath']`.
- Прочитать `JobStateService`: работа только с файлами (rename, getPath, getDoneDir и т.д.). Для БД нужен аналог с теми же методами, но без операций с файловой системой.
- Прочитать `QueueRepository`: уже есть `listPending`, `updateStatus`, `incrementAttempt`, `countByStatus`. Нет: установка status=processing с обновлением только updated_at; сброс «зависших» processing в pending.
- Зафиксировать формат данных задания для QueueRunner: ключи в camelCase (requestId, eventType, entityType, entityId, payload, rawPath, attempt и т.д.). Данные из БД приходят в snake_case — нужна нормализация при построении QueueJob в DatabaseQueueService.

### 6.2 Фаза 1: Репозиторий очереди и состояние заданий в БД

**Цель:** QueueRepository и слой «состояние задания» умеют ставить processing, done, failed и сбрасывать зависшие processing.

**1.1 QueueRepository — новые/уточнённые методы**

| Метод | Назначение | SQL/логика |
|-------|------------|------------|
| `setProcessing(int $jobId): bool` | Взять задание в работу | `UPDATE queue_jobs SET status = 'processing', updated_at = :now, processed_at = NULL, error_message = NULL WHERE id = :id AND status = 'pending'` (опционально: проверка status для избежания гонки). |
| `resetStaleProcessing(int $timeoutSeconds): int` | Вернуть зависшие задания в pending | `UPDATE queue_jobs SET status = 'pending', updated_at = :now WHERE status = 'processing' AND (strftime('%s', 'now') - strftime('%s', updated_at)) > :timeout`; вернуть количество обновлённых строк. |

- `updateStatus($jobId, $status, $errorMessage)` уже есть и подходит для done/failed.
- При необходимости добавить `getJobIdFromPath(string $path): ?int` (парсинг `db://queue_jobs/123` → 123) в хелпер или в сервис, который будет вызывать репозиторий по id.

**1.2 DatabaseJobStateService**

- Файл: `outgoing-webhook/services/Queue/DatabaseJobStateService.php`.
- Зависимости: `QueueRepository`, `ErrorService`, `int $maxAttempts`, `int $processingTimeout` (как у JobStateService).
- Методы (контракт как у JobStateService, работа с БД по id из пути):

| Метод | Поведение |
|-------|-----------|
| `markProcessing(QueueJob $job): ?QueueJob` | Из пути `db://queue_jobs/{id}` извлечь id. Вызвать `QueueRepository::setProcessing($id)`. При успехе вернуть тот же QueueJob; при неуспехе (например, запись уже взята) — null. |
| `markDone(QueueJob $job): void` | Извлечь id из пути, вызвать `QueueRepository::updateStatus($id, 'done', null)`. |
| `markFailed(QueueJob $job, string $reason, ?string $method = null): void` | Извлечь id, вызвать `QueueRepository::updateStatus($id, 'failed', $reason)`. При желании сохранять method в error_message или в отдельное поле (если появится). |
| `markRequeue(QueueJob $job): void` | Вернуть задание в очередь для повторной попытки: извлечь id, вызвать `QueueRepository::updateStatus($id, 'pending', null)` и `QueueRepository::incrementAttempt($id)`. Используется в QueueRunner при ошибке обогащения и attempt < max (вместо записи файла и rename в файловой версии). |
| `recoverProcessing(): void` | Вызвать `QueueRepository::resetStaleProcessing($this->processingTimeout)`. Опционально: для записей, которые перешли в pending после таймаута, не увеличивать attempt (как в файловой версии — там attempt увеличивается при следующей обработке). |
| `getMaxAttempts(): int` | Вернуть $this->maxAttempts. |

- Извлечение id: регулярное выражение или `str_starts_with($path, 'db://queue_jobs/')` и `(int) substr($path, strlen('db://queue_jobs/'))`.
- **Единый контракт с файловым JobStateService:** чтобы QueueRunner не различал тип очереди в ветке «ошибка обогащения, attempt < max», в файловом `JobStateService` добавить метод `markRequeue(QueueJob $job): void`: записать `$job->getData()` в файл по пути задания и переименовать файл в `getPendingDir() . '/' . basename($path)` (текущая логика, которая сейчас в QueueRunner). Тогда в QueueRunner при ошибке обогащения и attempt < max всегда вызывать `$this->jobState->markRequeue($processingJob)`; при attempt >= max — `markFailed()`. Для БД markRequeue реализован в DatabaseJobStateService (updateStatus pending + incrementAttempt).

**1.3 Нормализация данных задания (camelCase)**

- QueueRunner использует ключи camelCase: `$job['requestId']`, `$job['eventType']`, `$job['entityType']`, `$job['entityId']`, `$job['payload']`, `$job['rawPath']`, `$job['attempt']`.
- `QueueRepository::listPending()` возвращает строки БД в snake_case: `request_id`, `event_type`, `entity_type`, `entity_id`, `payload`, `attempt`, и т.д.
- В `DatabaseQueueService::listPending()` при формировании QueueJob для каждой строки привести массив к camelCase (request_id → requestId, event_type → eventType, created_at → createdAt и т.д.) и передать в QueueJob. Поле `rawPath` задать как `db://queue_jobs/{id}`, чтобы дальше по коду не обращаться к файлу по rawPath.

**Критерии приёмки фазы 1:**  
- В QueueRepository есть setProcessing и resetStaleProcessing, они корректно обновляют queue_jobs.  
- DatabaseJobStateService создан, принимает QueueJob с путём db://queue_jobs/{id}, вызывает репозиторий; getMaxAttempts возвращает заданное значение.

---

### 6.3 Фаза 2: Точка входа process-queue и выбор очереди из БД

**Цель:** При наличии БД и настроек «очередь из БД» process-queue использует DatabaseQueueService и DatabaseJobStateService; иначе — текущую файловую реализацию.

**2.1 Единая фабрика сервисов для process-queue**

- Сейчас `process-queue.php` вызывает `outgoingWebhookGetServices()`, который всегда создаёт файловые QueueService, JobStateService и QueueRunner (без контейнера и без БД).
- Вариант A: в `process-queue.php` в начале проверить конфиг и наличие контейнера/БД; если `DATABASE_TYPE=sqlite` и доступны queueRepository и database — собирать сервисы через ServiceContainer (queue как DatabaseQueueService, jobState как DatabaseJobStateService, остальные — taskDetails, commentDetails, enrichment и т.д. из контейнера) и создавать QueueRunner с этими зависимостями. Иначе — вызывать `outgoingWebhookGetServices()` как сейчас.
- Вариант B: вынести сборку «очередь + jobState + runner» в отдельную функцию (например в bootstrap или в отдельный файл), которая принимает конфиг и контейнер и возвращает массив [queue, jobState, runner] либо файловый, либо БД-вариант. process-queue.php вызывает эту функцию и дальше использует полученный runner.

**2.2 Регистрация в ServiceContainer**

- В контейнере нет фабрик для `queue` (как сервиса очереди для runner) и `jobState`. Добавить:
  - `queue`: при наличии queueRepository — `new DatabaseQueueService($this->get('queueRepository'), $this->get('errors'))`, иначе не регистрировать (process-queue будет использовать только файловый путь).
  - `jobState`: при наличии queueRepository — `new DatabaseJobStateService($this->get('queueRepository'), $this->get('errors'), OUTGOING_WEBHOOK_MAX_ATTEMPTS, OUTGOING_WEBHOOK_PROCESSING_TIMEOUT)` (константы вынести в конфиг или оставить в process-queue).
- Учесть, что QueueRunner в контейнере не регистрируется; он создаётся в index.php вручную. Для process-queue либо создавать runner в process-queue через контейнер (get('queue'), get('jobState'), get('enrichment'), …), либо оставить создание в фабрике/функции, которая возвращает готовый runner.

**2.3 Совместимость QueueRunner с заданиями из БД**

- QueueRunner не должен вызывать операции с файлами для заданий из БД:
  - После `markProcessing()` не делать `rename()` — это делает DatabaseJobStateService через БД.
  - При ошибке обогащения: при **attempt >= max** вызывать `$this->jobState->markFailed($processingJob, ...)`; при **attempt < max** — `$this->jobState->markRequeue($processingJob)` (markRequeue для файлов — запись файла и перемещение в pending; для БД — updateStatus(id, 'pending') и incrementAttempt). После markFailed и при invalid_job для пути db:// не вызывать `@unlink($processingJob->getPath())` — проверять `!str_starts_with($processingJob->getPath(), 'db://')`.
  - При успехе: `markDone()` для БД обновит запись в queue_jobs — файлов не трогаем.
- Итого: в QueueRunner для операций с файлом по getPath() (unlink, writeJson, rename) проверять, что путь не начинается с db://; для db:// только вызовы jobState (markDone, markFailed, markRequeue).

**2.4 Получение payload и rawPath для обогащения**

- Сейчас: `$raw = $job['payload'] ?? []; if ($rawPath !== '' && file_exists($rawPath)) { $raw = json_decode(file_get_contents($rawPath), true); }`. Для заданий из БД payload уже в $job['payload'] (полностью), rawPath = `db://queue_jobs/123` — file_exists не нужен и не должен использоваться. Оставить: если в $job есть payload, использовать его; иначе при не-db пути подгружать из файла. То есть при db:// всегда брать raw из $job['payload'] и формировать $raw = ['payload' => $job['payload']].

**2.5 Подсчёт очереди в ответе run()**

- QueueRunner в конце возвращает `'queue' => [ 'pending' => $this->queue->count($this->queue->getPendingDir()), ... ]`. Для DatabaseQueueService getPendingDir() возвращает `'db://queue/pending'`, а count(string) ожидает путь к папке. Нужно: в DatabaseQueueService метод count(string $statusOrDir): при получении строки типа `db://queue/pending` интерпретировать как статус pending и вызывать `$this->queueRepository->countByStatus('pending')`; аналогично для processing, done, failed. Либо в QueueRunner для БД вызывать не count(getPendingDir()), а отдельный метод типа getQueueCounts(): array — тогда в DatabaseQueueService реализовать getQueueCounts() и в QueueRunner при использовании БД-очереди подставлять эти счётчики в ответ.

**Критерии приёмки фазы 2:**  
- При DATABASE_TYPE=sqlite и доступной БД вызов process-queue.php использует задания из queue_jobs.  
- Задания проходят цикл: pending → processing → done (или failed). В БД статусы и даты обновляются.  
- Ответ run() содержит осмысленные счётчики очереди (pending, processing, done, failed) для БД.

---

### 6.4 Фаза 3: Восстановление зависших заданий (recoverProcessing) для БД

**Цель:** В начале каждого запуска обработки очереди записи queue_jobs со статусом processing и updated_at старше N минут переводились в pending.

**3.1 Вызов recoverProcessing**

- В QueueRunner в начале run() уже вызывается `$this->jobState->recoverProcessing()`. Для DatabaseJobStateService этот метод вызывает `QueueRepository::resetStaleProcessing($this->processingTimeout)`. Константа таймаута (например 900 секунд = 15 минут) должна совпадать с той, что используется в файловой версии (OUTGOING_WEBHOOK_PROCESSING_TIMEOUT).

**3.2 Поведение resetStaleProcessing**

- Условие «зависло»: `status = 'processing'` и `(текущее время - updated_at) > timeout`.
- Действие: UPDATE queue_jobs SET status = 'pending', updated_at = :now WHERE ... . Не менять attempt, чтобы при следующей обработке задание снова попал в цикл (при необходимости attempt можно увеличивать отдельно — уточнить по продукту).

**Критерии приёмки фазы 3:**  
- После принудительного прерывания процесса обработки (или симуляции «зависания»: вручную поставить запись в processing и старый updated_at) следующий запуск process-queue переводит эту запись в pending и затем обрабатывает её.

---

### 6.5 Фаза 4: Маркер «Activity First уже обработан» в БД

**Цель:** Проверка «уже обработан ли комментарий (requestId + taskId) для Activity First» опирается на БД; при наличии БД маркер записи тоже вести в БД (или считать маркером факт наличия записи в activity_first_metrics).

**4.1 Использование activity_first_metrics**

- После успешной синхронной обработки Activity First в bootstrap уже создаётся запись в activity_first_metrics (request_id, task_id, …). Эту запись можно считать маркером «уже обработан».
- Для проверки нужен метод в ActivityFirstMetricsRepository: `existsByRequestAndTask(string $requestId, string $taskId): bool` — запрос вида `SELECT 1 FROM activity_first_metrics WHERE request_id = :request_id AND task_id = :task_id LIMIT 1`.

**4.2 Изменения в TaskDetailsService**

- Конструктор: добавить опциональную зависимость `?ActivityFirstMetricsRepository $activityFirstMetricsRepository = null` (или получать репозиторий через контейнер, если TaskDetailsService создаётся из контейнера).
- `isActivityFirstProcessed(string $requestId, string $taskId): bool`:  
  - если передан activityFirstMetricsRepository — вызвать `$this->activityFirstMetricsRepository->existsByRequestAndTask($requestId, $taskId)` и вернуть результат;  
  - иначе — текущая логика `file_exists($stateFile)`.
- `markActivityFirstProcessed(string $requestId, string $taskId): void`: маркер «уже обработан» при синхронной обработке уже ставится записью в activity_first_metrics в bootstrap (create). Файловый маркер по-прежнему можно писать для обратной совместимости (или оставить только БД при наличии репозитория). То есть при наличии репозитория не обязательно вызывать create отсюда — запись создаётся выше по цепочке после успешной обработки. Если нужен явный «маркер без полной метрики» (например, чтобы пометить «пропущено, т.к. уже обработано»), можно добавить лёгкую запись в activity_first_metrics с минимальными полями или ввести отдельную таблицу activity_first_processed; в задаче принять решение: достаточно проверки existsByRequestAndTask по activity_first_metrics, а запись создаётся только при реальной обработке в bootstrap.

**4.3 Контейнер**

- TaskDetailsService в контейнере уже получает taskDetailsRepository. Добавить передачу activityFirstMetricsRepository (если есть) в TaskDetailsService для использования в isActivityFirstProcessed/markActivityFirstProcessed.

**Критерии приёмки фазы 4:**  
- При включённой БД вызов isActivityFirstProcessed($requestId, $taskId) после успешной синхронной обработки Activity First возвращает true (есть запись в activity_first_metrics).  
- Обработка того же комментария из очереди пропускается (status skipped, reason already_processed_sync) без дублирования обработки.

---

### 6.6 Фаза 5: Запись обогащённых данных в БД (желательно)

**Цель:** После обогащения сохранять результат в таблицу enriched_data.

**5.1 EnrichedDataRepository**

- Проверить наличие репозитория для enriched_data в проекте. Если нет — создать `outgoing-webhook/services/Database/Repositories/EnrichedDataRepository.php` с методом `create(array $data): ?int`. Поля таблицы enriched_data: request_id, event_type, entity_type, entity_id, enriched_at, source_method, response_time_ms, data (JSON). Параметры create принимать в snake_case, в data сохранять полный JSON обогащённого результата (или только нужные поля).

**5.2 Вызов из QueueRunner**

- После `$enriched = $this->enrichment->buildEnriched(...)` и при отсутствии ошибки: если доступен сервис/репозиторий для enriched_data (внедрить опционально в QueueRunner или через контейнер), вызвать создание записи с request_id, event_type, entity_type, entity_id, enriched_at = now(), source_method (из enriched, если есть), response_time_ms (если считаем), data = json_encode($enriched) или выбранные поля.

**5.3 Контейнер**

- Зарегистрировать EnrichedDataRepository и при необходимости передать в QueueRunner (или в отдельный EnrichedWriter, вызываемый из runner).

**Критерии приёмки фазы 5:**  
- После обработки задания из очереди в таблице enriched_data появляется запись с корректным request_id и данными обогащения.

---

### 6.7 Фаза 6: Конфигурация и документация

**6.1 Конфиг**

- Явно зафиксировать: при `DATABASE_TYPE=sqlite` и доступной БД очередь обрабатывается из queue_jobs (не из файлов). Опция `QUEUE_USE_DATABASE` не обязательна, если правило «sqlite ⇒ очередь из БД» закреплено. При отключённой БД или fallback на файлы — без изменений, очередь из queue/pending.

**6.2 Документация**

- В DOCS/OUTGOING-EVENTS/28-logs-db-vs-files-analysis.md добавить раздел «Использование БД для событий и очереди»: с момента включения DATABASE_TYPE=sqlite события пишутся в events, задания очереди — в queue_jobs; обработка очереди запускается тем же process-queue.php и берёт задания из БД; старые файловые данные не импортируются в БД.
- Кратко описать, как проверить работу: отправить событие → проверить запись в queue_jobs → вызвать process-queue → проверить смену статуса на done и появление записей в task_details/comment_details.

---

### 6.8 Фаза 7: Тестирование и пограничные случаи

- **Успешный цикл:** событие → events + queue_jobs (pending) → process-queue → задание в processing → обогащение → детали в БД/файлы → задание в done.
- **Ошибка обогащения, attempt < max:** задание остаётся в очереди (pending или повторная попытка), attempt увеличивается.
- **Ошибка обогащения, attempt >= max:** задание переводится в failed, error_message заполнен.
- **Зависшее задание:** запись в processing со старым updated_at → следующий run() → resetStaleProcessing переводит в pending → задание снова обрабатывается.
- **Activity First:** синхронная обработка создаёт запись в activity_first_metrics; при обработке того же requestId+taskId из очереди isActivityFirstProcessed возвращает true, задание помечается done со status skipped.
- **Обратная совместимость:** при отключённой БД (или отсутствии queueRepository) process-queue работает по-старому с queue/pending и файлами.

---

### 6.9 Риски и подводные камни

| Риск | Митигация |
|------|-----------|
| Разный формат ключей (snake_case в БД, camelCase в QueueRunner) | Нормализовать в DatabaseQueueService при построении QueueJob; единая карта полей request_id→requestId и т.д. |
| QueueRunner выполняет unlink/rename по пути задания | Проверять путь на префикс db://; для db:// не вызывать файловые операции, только методы jobState. |
| При ошибке обогащения и attempt < max файловая версия перезаписывает файл и перемещает в pending | Для БД: не вызывать markFailed (это ставит failed), а вызывать новый метод «вернуть в pending» (updateStatus pending + incrementAttempt) или отдельный markRequeue. |
| Счётчики очереди в ответе run() завязаны на count(dir) | Для DatabaseQueueService реализовать count по статусу или getQueueCounts() и подставлять в ответ. |
| Конкурентный запуск двух воркеров process-queue | setProcessing обновлять только записи в status=pending (WHERE status = 'pending'), чтобы одно и то же задание не взяли два процесса. |
| Таймаут recoverProcessing в секундах и тип updated_at | В SQLite сравнивать время через strftime или явное приведение; timeout задавать в секундах и сравнивать с (now - updated_at). |

---

## 7. Пошаговый план (краткий список подзадач)

1. **Фаза 1:** QueueRepository::setProcessing, resetStaleProcessing; DatabaseJobStateService; нормализация camelCase в DatabaseQueueService::listPending.
2. **Фаза 2:** Выбор очереди в process-queue (БД vs файлы); регистрация queue и jobState в контейнере; адаптация QueueRunner под db:// (без файловых операций, payload из job, счётчики очереди).
3. **Фаза 3:** recoverProcessing для БД (вызов resetStaleProcessing в начале run()).
4. **Фаза 4:** ActivityFirstMetricsRepository::existsByRequestAndTask; TaskDetailsService::isActivityFirstProcessed (и при необходимости markActivityFirstProcessed) с опорой на БД.
5. **Фаза 5 (желательно):** EnrichedDataRepository, запись в enriched_data из QueueRunner.
6. **Фаза 6:** Конфиг и обновление 28-logs-db-vs-files-analysis.md.
7. **Фаза 7:** Ручное/автотесты по сценариям выше.

---

## 8. Критерии приёмки

- [ ] При `DATABASE_TYPE=sqlite` и `QUEUE_ENABLED=true` новые задания попадают в **queue_jobs** (уже выполняется) и **обрабатываются** при запуске process-queue (или аналога), а не остаются в pending навсегда.
- [ ] В процессе обработки статусы записей в **queue_jobs** обновляются (processing → done / failed); при ошибке заполняется error_message, при успехе — processed_at.
- [ ] Зависшие задания (status=processing дольше заданного таймаута) при следующем запуске обработки возвращаются в pending (recoverProcessing для БД).
- [ ] Проверка «Activity First уже обработан» для пары (requestId, taskId) опирается на БД (запись в activity_first_metrics или отдельная таблица); при необходимости маркер записывается в БД при успешной обработке.
- [ ] В документации зафиксировано: работа с событиями ведётся через БД; перенос старых файловых данных в БД не предусмотрен.
- [ ] Обратная совместимость: при отключённой БД или fallback на файлы обработка очереди по-прежнему работает через файлы (queue/pending и т.д.).

---

## 9. История правок

| Дата | Изменение |
|------|-----------|
| 2026-01-30 | Создана задача: миграция на БД для хранения событий без переноса старых данных. |
