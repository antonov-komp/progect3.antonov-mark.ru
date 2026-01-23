 # Расшифровка payload: правила и примеры
 
 Дата создания: 2026-01-23 18:38 (UTC+03:00, Брест)
 
 ## Что означает «расшифровка»
 Расшифровка — это нормализация события и извлечение ключевых параметров
 (eventType, entityId, entityType), чтобы дальше можно было:
 - положить задание в очередь;
 - обогатить данные через REST API;
 - запускать бизнес‑логику (Activity First).
 
 Реализовано в `outgoing-webhook/bootstrap.php`.
 
 ## Нормализация eventType
 Функция: `outgoingWebhookNormalizeEventType()`
 
 - Приведение к верхнему регистру.
 - Удаление пробелов.
 - Удаление всех символов, кроме `A-Z`, `0-9` и `_`.
 - Если результат пустой — `UNKNOWN`.
 
 ## Извлечение entityId
 Функция: `outgoingWebhookExtractEntityId()`
 
 Приоритет извлечения из `payload.data`:
 1. `FIELDS_AFTER.TASK_ID`
 2. `FIELDS.TASK_ID`
 3. `TASK_ID`
 4. `FIELDS.ID`
 5. `FIELDS_AFTER.ID`
 6. `FIELDS_BEFORE.ID`
 7. `ID`
 
 После извлечения применяется нормализация:
 - пустые значения и `"0"` превращаются в `null`.
 
 ## Извлечение commentId (для ONTASKCOMMENTADD)
 Функция: `outgoingWebhookExtractCommentId()`
 
 Поиск в `payload.data` в таком порядке:
 - `FIELDS_AFTER.MESSAGE_ID`
 - `FIELDS.MESSAGE_ID`
 - `FIELDS_AFTER.ID`
 - `FIELDS.ID`
 - `MESSAGE_ID`
 - `ID`
 
 ## Обезличенные примеры
 Основаны на текущей схеме хранения (`TASK-014-06`), без реальных данных.
 
 ### raw.json (событие задачи)
 ```json
 {
   "eventType": "ONTASKADD",
   "receivedAt": "2026-01-23T18:38:00+03:00",
   "ip": "10.0.0.1",
   "payload": {
     "token": "****<TOKEN_LAST_4>",
     "event": "ONTASKADD",
     "data": {
       "FIELDS": {
         "ID": "T_<TASK_ID>",
         "TITLE": "Задача: <MASKED_TITLE>"
       }
     }
   }
 }
 ```
 
 ### event.log
 ```
 2026-01-23T18:38:00+03:00 | IP=10.0.0.1 | event=ONTASKADD | entityType=task | entityId=T_<TASK_ID>
 ```
 
 ### queue/pending/*.json
 ```json
 {
   "eventType": "ONTASKADD",
   "entityType": "task",
   "entityId": "T_<TASK_ID>",
   "rawPath": "/outgoing-webhook/logs/ONTASKADD/raw.json",
   "createdAt": "2026-01-23T18:38:00+03:00",
   "attempt": 0,
   "priority": "normal",
   "source": "outgoing-webhook"
 }
 ```
 
 ### enriched.json (обогащение задачи)
 ```json
 {
   "eventType": "ONTASKADD",
   "entityType": "task",
   "entityId": "T_<TASK_ID>",
   "enrichedAt": "2026-01-23T18:38:05+03:00",
   "source": {
     "method": "tasks.task.get",
     "responseTimeMs": 240
   },
   "rawRef": "/outgoing-webhook/logs/ONTASKADD/raw.json",
   "data": {
     "task": {
       "ID": "T_<TASK_ID>",
       "TITLE": "<MASKED_TITLE>",
       "UF_CRM_TASK": ["D_<DEAL_ID>"]
     }
   }
 }
 ```
 
 ## Ссылки на схемы
 Полные схемы raw/enriched/event.log/queue — в `DOCS/TASKS/TASK-014-06-payload-schemas.md`.
 
 ## Изменения
 - 2026-01-23 18:38 (UTC+03:00, Брест): добавлены правила расшифровки и примеры.
