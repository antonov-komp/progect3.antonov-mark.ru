# Уровни обработки: токен (события) vs задача (TASK)

**Дата:** 2026-01-27  
**Контекст:** Модуль исходящих событий. На уровне токена добавлены методы для **сделок** (ONCRMDEAL*). На уровне **задач** логика исторически завязана на ONTASK* и комментарии.

---

## 1. Уровень токена (события)

Под «уровнем токена» понимаются: приём события, нормализация типа, определение сущности, обогащение (enrichment) и сохранение в БД/файлы.

### 1.1 Что реализовано для сделок (DEAL)

| Компонент | Файл | Назначение |
|-----------|------|------------|
| Реестр событий | `DOCS/OUTGOING-EVENTS/02-registered-events.md` | `ONCRMDEALADD`, `ONCRMDEALUPDATE`, `ONCRMDEALDELETE` |
| Карта обогащения | Там же | `ONCRMDEAL*` → `crm.deal.get` |
| Тип сущности | `EntityIdentityService::resolveEntityType()` | `ONCRMDEAL*` → `'deal'` |
| REST-метод обогащения | `EnrichmentService::resolveMethod()` | `ONCRMDEAL*` → `crm.deal.get` |
| Обработчик сущности | `DealHandler` | `entityType = 'deal'`, словари категорий/стадий |
| Извлечение ID | `EntityIdentityService::extractEntityId()` | `data.FIELDS.ID` / `data.FIELDS_AFTER.ID` (подходит для CRM) |

Для сделок используется общий порядок разбора payload: сначала TASK_ID (для задач), затем `FIELDS.ID` / `FIELDS_AFTER.ID` — для CRM-событий, в т.ч. сделок, этого достаточно.

### 1.2 Где используется

- **EventProcessor / DatabaseEventProcessor:** разбирают payload, вызывают `identity->extractEntityId`, `resolveEntityType`, пишут событие (БД или `logs/{EVENT_TYPE}/raw.json`, `event.log`).
- **Очередь:** при `QUEUE_ENABLED` создаётся задание (БД `queue_jobs` или файл в `queue/pending`).
- **Обработчик очереди (process-queue):** для **любого** типа события, в т.ч. DEAL, вызывается `EnrichmentService::buildEnriched()` → `crm.deal.get` → `logs/{EVENT_TYPE}/enriched.json`, затем `detectFieldChanges` для трекинга состояния.

Итого: на уровне токена сделки поддерживаются «симметрично» другим CRM-событиям (лиды, контакты и т.д.).

---

## 2. Уровень задачи (TASK)

Под «уровнем задачи» — специализированная логика для **задач** и **комментариев к задачам**: отдельные обработчики, детали, Activity, файлы в сделках.

### 2.1 Обработчики в `index.php`

- **TaskEventHandler**  
  - Условие: `str_starts_with($eventType, 'ONTASK')` и есть `entityId`.  
  - Действия: `tasks.task.get` → `TaskDetailsService::buildDetails` → `writeDetailsRu` (файл + при наличии — БД).  
  - События сделок не обрабатываются.

- **CommentEventHandler**  
  - Условие: `$eventType === 'ONTASKCOMMENTADD'` и есть `entityId` (ID задачи).  
  - Действия: получение комментария, запись деталей, при необходимости — синхронный Activity (файлы в сделках и т.п.).  
  - События сделок не обрабатываются.

Для `ONCRMDEAL*` оба обработчика ничего не делают, что корректно: это не задачи и не комментарии.

### 2.2 Запись деталей

- **TaskDetailsService::writeDetailsRu**  
  - Пишет только при `str_starts_with($eventType, 'ONTASK')` (файл `task-details.log`, при наличии — БД).  
  - Для сделок вызовов нет.

- **TaskDetailsService::writeDetails** (из `QueueRunner`)  
  - Берёт данные из `extractTaskData($enriched)` → `$enriched['data']['task']`.  
  - Для сделок в `enriched['data']` есть `deal`, а не `task` → `extractTaskData` возвращает `null`, запись деталей задачи не выполняется.  
  - Это ожидаемо: отдельного «deal details» в этом методе нет.

- **CommentWriter / CommentDetailsService::writeDetailsRu**  
  - Ограничение по типу: `str_starts_with($eventType, 'ONTASK')`.  
  - Для сделок не используются.

### 2.3 Activity и файлы в сделках

- Логика Activity (в т.ч. ActivityFirst) и обновление файловых полей сделок завязана на **комментарии к задаче** (`ONTASKCOMMENTADD`), CRM-связи задачи и конфиг `activity/config.php` (типы, поля сделки и т.д.).
- События сделок (`ONCRMDEAL*`) в этой цепочке не участвуют.

---

## 3. Трекинг сделок: новые + «что было → что стало»

С 2026-01-27 для сделок включена **синхронная** обработка в `index.php`:

- **DealEventHandler** — обрабатывает `ONCRMDEALADD` и `ONCRMDEALUPDATE`.
- Для каждого события: `crm.deal.get` → актуальные данные сделки.
- **StateStorage::detectFieldChanges** сравнивает с предыдущим снимком:
  - если есть «предыдущее» состояние — сохраняет diff по полям (`old` / `new`);
  - обновляет снимок.

**Хранение:** при `DATABASE_TYPE=sqlite` данные пишутся в **БД** (та же `events.db`):

| Что | ГДЕ (БД) |
|-----|----------|
| Снимок сделки | Таблица `entity_states` (поле `state` — JSON) |
| Изменения полей | Таблица `entity_field_changes` (поле `changes` — JSON) |

Если БД недоступна, используется **файловый** fallback:

| Что | Где (файлы) |
|-----|-------------|
| Снимок сделки | `outgoing-webhook/logs/state/deal_{id}.json` |
| Изменения полей | `outgoing-webhook/logs/field-changes/deal_{id}_{Ymd_His}.json` |
| Лог изменений | `outgoing-webhook/logs/field-changes/field-changes.log` |

**Тест с реальным ID сделки:**

```bash
php outgoing-webhook/tools/test-token-events.php --event=ONCRMDEALADD --deal-id=13177 \
  --endpoint="https://progect3.antonov-mark.ru/outgoing-webhook/index.php"
php outgoing-webhook/tools/test-token-events.php --event=ONCRMDEALUPDATE --deal-id=13177 \
  --endpoint="https://progect3.antonov-mark.ru/outgoing-webhook/index.php"
```

После ADD появляется снимок в `entity_states`. После UPDATE, если поля менялись — записи в `entity_field_changes`. Статистика: `php outgoing-webhook/tools/check-db-stats.php` (блоки «Снимки сущностей», «Изменения полей»).

**Полная проверка (чек-лист + скрипт):** см. `27-verify-deal-tracking.md` и `php outgoing-webhook/tools/verify-deal-tracking.php --deal-id=13177 --endpoint="https://.../outgoing-webhook/index.php"`.

---

## 4. Сводная таблица

| Аспект | Задачи (ONTASK*) | Сделки (ONCRMDEAL*) |
|--------|-------------------|----------------------|
| Приём и лог события | ✅ | ✅ |
| Очередь (при включённой) | ✅ | ✅ |
| Обогащение (REST) | `tasks.task.get` | `crm.deal.get` |
| `enriched.json` | ✅ | ✅ |
| `task-details.log` / БД task_details | ✅ | ❌ |
| `deal-details.log` / БД deal_details | ❌ | ✅ (crm.deal.get по каждому событию) |
| `comment-details` / Activity | Только ONTASKCOMMENTADD | ❌ |
| Трекинг изменений (`detectFieldChanges`) | ✅ | ✅ |
| Отдельный handler в `index.php` | TaskEventHandler, CommentEventHandler | **DealEventHandler** (ADD/UPDATE) |

---

## 5. Рекомендации

1. **Токен (DEAL)**  
   - Текущей поддержки достаточно для приёма, обогащения и трекинга изменений сделок.  
   - При необходимости можно явно задокументировать в коде, что `extractEntityId` для CRM опирается на `FIELDS.ID` / `FIELDS_AFTER.ID`.

2. **Отдельный «DealEventHandler»**  
   - Имеет смысл только если нужна **синхронная** выгрузка деталей сделки (аналог `task-details`) при приёме вебхука.  
   - Сейчас обогащение сделок выполняется в очереди → `enriched.json`. Для многих сценариев этого достаточно.

3. **«Deal details» (аналог task-details)**  
   - Если понадобится отдельный лог/таблица «deal details», можно ввести `DealDetailsService` и вызывать его из `QueueRunner` при `entityType === 'deal'`, по аналогии с `writeDetails` для задач.

4. **Очередь: БД vs файлы**  
   - `process-queue` обрабатывает **файловую** очередь (`queue/pending`).  
   - При использовании `DatabaseEventProcessor` задания попадают в `queue_jobs`.  
   - Чтобы обрабатывать в т.ч. сделки через БД-очередь, нужно перевести `process-queue` / `QueueRunner` на чтение из БД (см. `TASK-027-migration-status`).

5. **Тестирование сделок**  
   - `test-token-events.php` по умолчанию читает события из `02-registered-events.md` (в т.ч. ONCRMDEAL*).  
   - Пример:  
     `php outgoing-webhook/tools/test-token-events.php --event=ONCRMDEALADD`  
   - Проверка обогащения: `logs/ONCRMDEALADD/enriched.json` после обработки очереди.

---

## 6. Исправления в репозитории (2026-01-27)

- В `02-registered-events.md`: опечатка `ONCRMUSERFIELUPDATE` → `ONCRMUSERFIELDUPDATE`, выравнивание формата списка для `ONTASKCOMMENTADD`.
- Трекинг сделок: снимки и изменения полей сохраняются в **БД** (`entity_states`, `entity_field_changes`), если `DATABASE_TYPE=sqlite`. Иначе — файлы `logs/state/`, `logs/field-changes/`.
- Детали сделок: по каждому `ONCRMDEALADD` / `ONCRMDEALUPDATE` выполняется **дозапрос** `crm.deal.get`, результат пишется в `deal_details` и в `logs/.../deal-details.log`. В `deal_details.details_resolved` хранится **пользовательское представление** полей. Отдельные столбцы для **ключевых полей**: `stage_id` / `stage_title` (тег + расшифровка), `category_id` / `category_title` (воронка), `assigned_by_id` / `assigned_by_name`, `modify_by_id` / `modify_by_name` (ответственный и кто изменил — «Имя Фамилия» + ID через `user.get`). Утилиты: `show-deal-resolved.php`, `check-db-stats.php`.

---

## 7. Связанные файлы

- `outgoing-webhook/index.php` — точка входа, вызов TaskEventHandler, CommentEventHandler, DealEventHandler
- `outgoing-webhook/services/Event/EventProcessor.php`, `DatabaseEventProcessor.php` — приём, лог, очередь
- `outgoing-webhook/services/Event/Handlers/TaskEventHandler.php`, `CommentEventHandler.php`, `DealEventHandler.php`
- `outgoing-webhook/services/Identity/EntityIdentityService.php` — `resolveEntityType`, `extractEntityId`  
- `outgoing-webhook/services/Enrichment/EnrichmentService.php` — `resolveMethod`, `buildEnriched`  
- `outgoing-webhook/services/Enrichment/EntityHandlers/DealHandler.php`  
- `outgoing-webhook/services/Enrichment/StateStorage.php` — `detectFieldChanges` (БД или файлы)
- `outgoing-webhook/services/Database/Repositories/EntityStateRepository.php` — снимки в БД
- `outgoing-webhook/services/Database/Repositories/EntityFieldChangesRepository.php` — изменения полей в БД
- `outgoing-webhook/services/Database/Repositories/DealDetailsRepository.php` — детали сделок в БД
- `outgoing-webhook/services/Crm/DealDetailsService.php` — форматирование и запись deal_details / deal-details.log
- `outgoing-webhook/services/Crm/DealFieldsResolver.php` — пользовательское представление полей; `extractKeyFields` для стадии/воронки/ответственный/кто изменил
- `outgoing-webhook/services/Crm/UserResolver.php` — `user.get` → «Имя Фамилия» + ID, кэш в dicts
- `outgoing-webhook/services/Queue/QueueRunner.php` — обогащение, `writeDetails`, комментарии, `detectFieldChanges`  
- `outgoing-webhook/services/Task/TaskDetailsService.php` — `writeDetails`, `writeDetailsRu`, `extractTaskData`  
- `outgoing-webhook/tools/process-queue.php`, `process-queue-cli.php` — обработка очереди  
- `DOCS/OUTGOING-EVENTS/02-registered-events.md` — реестр и карта обогащения  
