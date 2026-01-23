# TASK-017-QA: Проверка рефакторинга обработки очереди outgoing-webhook
**Дата создания:** 2026-01-23 21:30 (UTC+03:00, Брест)  
**Статус:** draft  
**Приоритет:** Средний  
**Исполнитель:** Тестировщик  

## Описание
Провести QA‑проверку рефакторинга задачи 017: обработка очереди `outgoing-webhook`
разнесена на сервисы, добавлена CLI‑обёртка, сохранены форматы логов и файлов.
Нужно подтвердить, что поведение не изменилось, а новые компоненты работают
стабильно.

## Контекст
Рефакторинг выполнен для `outgoing-webhook/tools/process-queue.php`:
логика вынесена в сервисы `outgoing-webhook/services/*`, добавлен `QueueRunner`,
и CLI‑скрипт `process-queue-cli.php`. Сохраняются контракты форматов `raw.json`,
`enriched.json`, `event.log`, `task-details.log`, `comment-details.log`.

## Анализ проделанной работы (факты по коду)
- Создана доменная структура сервисов в `outgoing-webhook/services/`:
  `Config`, `Rest`, `Queue`, `Enrichment`, `Dicts`, `Logging`, `Task`.
- `outgoing-webhook/tools/process-queue.php` содержит инициализацию сервисов
  и shim‑функции, основной прогон выполняет `QueueRunner::run()`.
- Добавлена CLI‑обёртка `outgoing-webhook/tools/process-queue-cli.php`
  (пробрасывает `limit` и вызывает `process-queue.php`).
- Лог шагов очереди записывается через `QueueStepLogger` в
  `outgoing-webhook/logs/queue-steps.log`.
- Enrichment разбит на handlers по сущностям в
  `outgoing-webhook/services/Enrichment/EntityHandlers/*`.

## Объект тестирования
- `outgoing-webhook/tools/process-queue.php`
- `outgoing-webhook/tools/process-queue-cli.php`
- `outgoing-webhook/services/Queue/QueueRunner.php`
- `outgoing-webhook/services/Queue/QueueService.php`
- `outgoing-webhook/services/Queue/JobStateService.php`
- `outgoing-webhook/services/Rest/RestService.php`
- `outgoing-webhook/services/Enrichment/EnrichmentService.php` + handlers
- `outgoing-webhook/services/Dicts/DictCacheService.php`
- `outgoing-webhook/services/Logging/ErrorService.php`
- `outgoing-webhook/services/Logging/QueueStepLogger.php`
- `outgoing-webhook/services/Task/TaskDetailsService.php`
- `outgoing-webhook/services/Task/CommentDetailsService.php`

## Риски регрессий
- Нарушение форматов логов/файлов (`raw.json`, `enriched.json`, `event.log`,
  `task-details.log`, `comment-details.log`).
- Неправильные переходы `pending → processing → done/failed`.
- Неверные ретраи в REST‑слое и ошибочные лимиты.
- Потеря совместимости shim‑функций из `process-queue.php`.
- Отсутствие/повреждение новых логов шагов `queue-steps.log`.

## Требования
### Функциональные
- Обработка очереди сохраняет прежнее поведение.
- CLI‑обёртка корректно запускает обработчик.
- Форматы логов/JSON не меняются.

### Нефункциональные
- Ошибки пишутся в `logs/errors/` с прежними ключами.
- Новые логи шагов пишутся без влияния на старые логи.

## План тестов
### A. Кодовый уровень (CLI/логика)
1. **Smoke‑test пустой очереди**
   - Запуск: `php outgoing-webhook/tools/process-queue-cli.php --limit=5`
   - Ожидание: JSON‑ответ с `processed: 0`, корректные значения `queue.*`.
2. **Обработка 1 валидного задания**
   - В `queue/pending/` положить валидный job‑файл (существующий из текущих
     данных либо подготовленный JSON‑файл по действующему формату).
   - Запуск: `php outgoing-webhook/tools/process-queue-cli.php --limit=1`
   - Ожидание:
     - файл перемещён в `queue/done/`;
     - в `logs/<EVENT_TYPE>/enriched.json` сформирован результат;
     - записан шаг `started` и `finished` в `logs/queue-steps.log`.
3. **Ошибка REST и ретраи**
   - Индуцировать REST‑ошибку (например, временно изменить токен/URL).
   - Запуск: `php outgoing-webhook/tools/process-queue-cli.php --limit=1`
   - Ожидание:
     - при 1‑2 попытках файл возвращается в `queue/pending/`;
     - при достижении max attempts — перенос в `queue/failed/`;
     - запись об ошибке в `logs/errors/`.
4. **Проверка shim‑функций**
   - Вызов shim‑функций через `process-queue.php` (например,
     `outgoingWebhookResolveMethod`, `outgoingWebhookGetDict`) без прямого
     обращения к классам.
   - Ожидание: корректные результаты без фатальных ошибок.

### B. Реальные действия на портале (ручные сценарии)
1. **Сущность “Сделка/Лид/Контакт/Компания”**
   - Создать или обновить сущность в Bitrix24.
   - Ожидание:
     - событие попадает в `queue/pending/`, затем переходит в `done/`;
     - в `logs/<EVENT_TYPE>/enriched.json` корректные данные;
     - `event.log` без изменений формата.
2. **Задача + комментарий**
   - Создать задачу, затем добавить комментарий (с вложением и без).
   - Ожидание:
     - запись в `task-details.log` и `comment-details.log`;
     - при комментарии используется `CommentDetailsService` без падений.
3. **Смарт‑процесс / пользователь**
   - Изменить сущность смарт‑процесса и данные пользователя.
   - Ожидание: корректный `enriched.json` и запись шагов очереди.

## Проверяемые артефакты
- `outgoing-webhook/queue/pending|processing|done|failed`
- `outgoing-webhook/logs/<EVENT_TYPE>/raw.json`
- `outgoing-webhook/logs/<EVENT_TYPE>/enriched.json`
- `outgoing-webhook/logs/<EVENT_TYPE>/event.log`
- `outgoing-webhook/logs/task-details.log`
- `outgoing-webhook/logs/comment-details.log`
- `outgoing-webhook/logs/errors/*`
- `outgoing-webhook/logs/queue-steps.log`

## Ожидаемые результаты
- Обработка очереди стабильна, формат файлов/логов сохранён.
- CLI‑запуск работает, возвращает корректный JSON.
- Ошибки логируются, ретраи соблюдаются.
- Новые сервисы не меняют внешнее поведение.

## Результаты тестирования (факт)
### Задачи (Bitrix24)
- `ONTASKADD`, `ONTASKUPDATE`, `ONTASKCOMMENTADD` — события приходят, логи пишутся.
- В `ONTASKCOMMENTADD` присутствуют системные комментарии (Автор=0). Факт подтверждён
  строками в `comment-details.log`.
- `ONTASKDELETE` — **не подтверждено в текущем тесте**:
  - `event.log` содержит только тестовую запись;
  - `event.get` в Bitrix24 вернул пустой список регистраций (`result: []`).

## Критерии приёмки
- [ ] Все сценарии A и B выполнены без регрессий.
- [ ] Форматы логов/JSON совпадают с текущими контрактами.
- [ ] Переходы между состояниями очереди корректны.
- [ ] `queue-steps.log` содержит пары `started/finished` на каждый job.
- [ ] Ошибки фиксируются в `logs/errors/` без потери данных.

## Тестирование (как сдаём результаты)
### Нужно предоставить от тестировщика
- Вывод CLI‑запуска (JSON).
- Список перемещённых файлов в очереди.
- Фрагменты логов: `enriched.json`, `event.log`, `queue-steps.log`,
  `task-details.log`, `comment-details.log` (по 5–10 строк/фрагментов).

## История правок
- 2026-01-23 21:30 (UTC+03:00, Брест): создан черновик QA‑задачи.
