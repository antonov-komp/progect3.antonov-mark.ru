% TASK-031: Полный снимок новой задачи (ONTASKADD)

**Дата создания:** 2026-02-05 16:10 (UTC+3, Брест)  
**Статус:** Черновик  
**Приоритет:** Средний  
**Исполнитель:** Laravel/Bitrix24 интегратор (Backend PHP + Bitrix REST 3.0)

## Описание
При событии ONTASKADD сохранять полный снимок новой задачи в отдельную таблицу `new_task_details` (SQLite `events.db`), включая сырой ответ Bitrix REST 3.0 и извлечённые ключевые поля. Текущая таблица `task_details` остаётся без изменений.

## Контекст
Сейчас ONTASKADD в БД содержит только ID и минимальные поля, что мешает анализу задач и отладке. Нужно фиксировать полную «внутрянку» задачи сразу при создании.

## Модули и компоненты
- `outgoing-webhook/services/Event/Handlers/TaskEventHandler.php` — точка входа ONTASKADD.  
- Новый сервис: `NewTaskDetailsService` (папка `services/Task/` или `services/Task/Details/`).  
- Новый репозиторий: `NewTaskDetailsRepository` (папка `services/Task/` или `services/Database/Repositories/`).  
- Миграция/скрипт для SQLite `events.db`: таблица `new_task_details`.  
- Логи: каталог `logs/ONTASKADD_NEW/` (по аналогии с существующими).

## Требования
- При ONTASKADD выполнить вызов Bitrix REST 3.0 (`tasks.task.get` или актуальный v3 метод) для получения полной задачи.  
- Сохранить:  
  - `raw_payload` — полный JSON ответа (без усечения, кроме защитного лимита размера).  
  - `extracted` — JSON с ключевыми полями:  
    - `id`, `title`, `description`, `status/state`, `priority`, `createdBy`, `responsibleId`, `accomplices`, `auditors`  
    - `groupId/project`, `stageId/kanban`, `tags`, `checklist`, `UF_CRM_TASK`, `files/attachments`  
    - даты: `created`, `deadline`, `startDatePlan/endDatePlan`, фактические даты (если доступны)  
    - `timeSpent/timeEstimate`, кастомные `UF`-поля (как есть, отдельным блоком)  
  - Метаданные: `requestId`, `eventType`, `taskId`, `createdAt`.  
- Не ломать текущую запись в `task_details`; новая таблица — параллельно.  
- Ошибки REST логировать, не ронять обработку.

## Структура таблицы `new_task_details`
- `id` INTEGER PRIMARY KEY  
- `request_id` TEXT NOT NULL  
- `event_type` TEXT NOT NULL  
- `task_id` TEXT NOT NULL  
- `raw_payload` TEXT NOT NULL (JSON)  
- `extracted` TEXT NOT NULL (JSON)  
- `created_at` TEXT NOT NULL DEFAULT `datetime('now')`

## Ступенчатые подзадачи
1. Добавить миграцию/скрипт для `new_task_details` в `events.db` (SQLite).  
2. Создать `NewTaskDetailsRepository` с методами `create(...)`.  
3. Создать `NewTaskDetailsService`: принимает `taskData`, формирует `extracted`, пишет raw + extracted.  
4. Обновить `TaskEventHandler` для ONTASKADD: после `tasks.task.get` вызывать новый сервис/репозиторий.  
5. Добавить логирование (файл + БД) с `requestId/taskId/eventType`.  
6. Покрыть smoke-тестом на реальной задаче: убедиться, что запись в `new_task_details` содержит полный JSON.

## API-методы Bitrix24 (REST 3.0)
- Основной: `tasks.task.get` (v3) — получение полной задачи по ID.  
- При необходимости: параметры select/expand для чеклистов, файлов, CRM-связей, кастомных UF.  
- Документация: https://context7.com/bitrix24/rest/ (раздел Tasks v3).

## Технические требования
- Хранить сырой ответ целиком, но предусмотреть лимит размера/обрезку больших вложений (обсудить).  
- JSON сохранять в UTF-8, без потери ключей/типов.  
- Не менять текущую таблицу `task_details` и формат `formatted_details`.  
- Логирование ошибок REST без прерывания основной обработки события.

## Критерии приёмки
- [ ] Таблица `new_task_details` создана и доступна в `events.db`.  
- [ ] При ONTASKADD записывается сырое тело ответа REST и извлечённые поля.  
- [ ] В `extracted` присутствуют ключевые поля (`title/description/status/responsibleId/...`).  
- [ ] Ошибки REST логируются без падения обработчика.  
- [ ] Существующий поток `task_details` не изменён и продолжает работать.

## Тестирование
1. Создать тестовую задачу → получить ONTASKADD → проверить запись в `new_task_details` (raw и extracted).  
2. Проверить извлечение ключевых полей (`title`, `responsibleId`, `status`, CRM-связи).  
3. Проверить поведение при больших payload (если лимит/усечение включены).  
4. Проверить логи на отсутствие ошибок.

## История правок
- 2026-02-05 16:10 (UTC+3, Брест): Черновик составлен.
