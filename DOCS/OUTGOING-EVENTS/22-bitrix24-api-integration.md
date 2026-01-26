# Интеграция с Bitrix24 REST API

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает все используемые методы Bitrix24 REST API в модуле `outgoing-webhook`: методы обогащения, методы для задач и комментариев, методы для файлов, методы для справочников, обработка ошибок.

---

## Методы обогащения данных

### CRM

#### crm.deal.get

**Назначение:** Получение данных сделки

**Параметры:**
```php
['id' => (int) $dealId]
```

**Использование:** для событий `ONCRMDEAL*`

**Документация:** https://context7.com/bitrix24/rest/crm.deal.get

**EntityHandler:** `DealHandler`

**Справочники:**
- `categories` — через `crm.category.list`
- `stages` — через `crm.status.list`

---

#### crm.lead.get

**Назначение:** Получение данных лида

**Параметры:**
```php
['id' => (int) $leadId]
```

**Использование:** для событий `ONCRMLEAD*`

**Документация:** https://context7.com/bitrix24/rest/crm.lead.get

**EntityHandler:** `LeadHandler`

**Справочники:**
- `stages` — через `crm.status.list`

---

#### crm.contact.get

**Назначение:** Получение данных контакта

**Параметры:**
```php
['id' => (int) $contactId]
```

**Использование:** для событий `ONCRMCONTACT*`

**Документация:** https://context7.com/bitrix24/rest/crm.contact.get

**EntityHandler:** `ContactHandler`

---

#### crm.company.get

**Назначение:** Получение данных компании

**Параметры:**
```php
['id' => (int) $companyId]
```

**Использование:** для событий `ONCRMCOMPANY*`

**Документация:** https://context7.com/bitrix24/rest/crm.company.get

**EntityHandler:** `CompanyHandler`

---

#### crm.item.get

**Назначение:** Получение данных смарт-процесса

**Параметры:**
```php
[
    'entityTypeId' => (int) $entityTypeId,
    'id' => (int) $itemId
]
```

**Использование:** для событий `ONCRMITEM*`

**Документация:** https://context7.com/bitrix24/rest/crm.item.get

**EntityHandler:** `SmartProcessHandler`

**Особенности:**
- Требуется `ENTITY_TYPE_ID` из payload

**Справочники:**
- `types` — через `crm.type.list`
- `categories` — через `crm.category.list`

---

#### crm.userfield.list

**Назначение:** Получение списка пользовательских полей CRM

**Параметры:**
```php
[]
```

**Использование:** для событий `ONCRMUSERFIELD*`

**Документация:** https://context7.com/bitrix24/rest/crm.userfield.list

**EntityHandler:** `CrmUserFieldHandler`

---

### Задачи

#### tasks.task.get

**Назначение:** Получение данных задачи

**Параметры:**
```php
['id' => (int) $taskId]
```

**Использование:** для событий `ONTASK*`

**Документация:** https://context7.com/bitrix24/rest/tasks.task.get

**EntityHandler:** `TaskHandler`

**Особенности:**
- Используется как для обогащения, так и для быстрого получения деталей в `index.php`

---

### Пользователи и проекты

#### user.get

**Назначение:** Получение данных пользователя

**Параметры:**
```php
['id' => (int) $userId]
```

**Использование:** для событий `ONUSER*`

**Документация:** https://context7.com/bitrix24/rest/user.get

**EntityHandler:** `UserHandler`

---

#### sonet_group.get

**Назначение:** Получение данных проекта (группы)

**Параметры:**
```php
['id' => (int) $groupId]
```

**Использование:** для событий `SONET_GROUP_*`

**Документация:** https://context7.com/bitrix24/rest/sonet_group.get

**EntityHandler:** `ProjectHandler`

---

## Методы для задач и комментариев

### task.item.getdata

**Назначение:** Получение данных задачи (включая CRM-связи)

**Параметры:**
```php
['TASKID' => (int) $taskId]
```

**Использование:** для загрузки CRM-связей задачи (`UF_CRM_TASK`)

**Документация:** https://context7.com/bitrix24/rest/task.item.getdata

**Где используется:**
- `TaskDetailsService::loadCrmLinks()`

---

### task.commentitem.get

**Назначение:** Получение данных комментария задачи

**Параметры:**
```php
[
    'taskId' => (int) $taskId,
    'itemId' => (int) $commentId
]
```

**Использование:** для получения данных комментария (первая попытка)

**Документация:** https://context7.com/bitrix24/rest/task.commentitem.get

**Где используется:**
- `CommentDetailsService::fetchDetails()` (первая попытка)

---

### task.commentitem.getlist

**Назначение:** Получение списка комментариев задачи

**Параметры (варианты):**
```php
// Вариант 1: фильтр по ID
[
    'taskId' => (int) $taskId,
    'ORDER' => ['ID' => 'DESC'],
    'FILTER' => ['ID' => (int) $commentId]
]

// Вариант 2: без фильтра (последние комментарии)
[
    'taskId' => (int) $taskId,
    'ORDER' => ['ID' => 'DESC'],
    'FILTER' => []
]

// Вариант 3: фильтр по дате (последние 10 минут)
[
    'taskId' => (int) $taskId,
    'ORDER' => ['POST_DATE' => 'DESC'],
    'FILTER' => ['>=POST_DATE' => $recentFrom]
]

// Вариант 4: альтернативные параметры
[
    'taskId' => (int) $taskId,
    'arOrder' => ['POST_DATE' => 'DESC'],
    'arFilter' => ['>=POST_DATE' => $recentFrom]
]
```

**Использование:** для получения данных комментария (fallback попытки)

**Документация:** https://context7.com/bitrix24/rest/task.commentitem.getlist

**Где используется:**
- `CommentDetailsService::fetchDetails()` (fallback попытки)

---

### im.dialog.messages.get

**Назначение:** Получение сообщений диалога (чата)

**Параметры:**
```php
// Вариант 1
['DIALOG_ID' => 'chat' . $chatId, 'LIMIT' => 50]

// Вариант 2
['dialog_id' => 'chat' . $chatId, 'limit' => 50]
```

**Использование:** для получения данных комментария через чат (fallback)

**Документация:** https://context7.com/bitrix24/rest/im.dialog.messages.get

**Где используется:**
- `CommentDetailsService::fetchChatMessageDetails()`

**Особенности:**
- Используется, когда комментарий не найден через `task.commentitem.get`
- `DIALOG_ID` формируется как `'chat' . $chatId`

---

## Методы для файлов

### task.item.getfiles

**Назначение:** Получение списка прикреплённых файлов задачи

**Параметры:**
```php
['TASKID' => (int) $taskId]
```

**Использование:** для получения списка файлов задачи

**Документация:** https://context7.com/bitrix24/rest/task.item.getfiles

**Где используется:**
- `TaskFilesService::getAttachedFiles()`

---

### tasks.task.files.attach

**Назначение:** Прикрепление файла к задаче

**Параметры:**
```php
[
    'taskId' => (int) $taskId,
    'fileId' => (int) $fileId
]
```

**Использование:** для прикрепления файлов к задаче (ActivityFirst)

**Документация:** https://context7.com/bitrix24/rest/tasks.task.files.attach

**Где используется:**
- `TaskFilesService::attachFiles()`
- `CommentDetailsService::processActivityFirst()`

---

### disk.file.get

**Назначение:** Получение информации о файле из Bitrix24 Disk

**Параметры:**
```php
['id' => (int) $fileId]
```

**Использование:** для получения `downloadUrl` файла

**Документация:** https://context7.com/bitrix24/rest/disk.file.get

**Где используется:**
- `TaskFilesService::getDiskFileInfo()`
- `DealFileService::buildDealFileData()`

---

### crm.deal.update

**Назначение:** Обновление сделки

**Параметры:**
```php
[
    'id' => (int) $dealId,
    'fields' => [
        'UF_CRM_1759233362672' => [
            ['fileData' => [$name, $base64]],
            // ... другие файлы
        ]
    ]
]
```

**Использование:** для обновления файлового поля сделки (ActivityFirst)

**Документация:** https://context7.com/bitrix24/rest/crm.deal.update

**Где используется:**
- `DealFileService::updateDealFiles()`
- `CommentDetailsService::processActivityFirst()`

**Важно:** все существующие файлы должны быть включены в обновление, иначе они будут удалены.

---

## Методы для справочников

### crm.status.list

**Назначение:** Получение списка статусов/стадий

**Параметры (варианты):**
```php
// Для стадий сделок
['filter' => ['ENTITY_ID' => 'DEAL_STAGE']]

// Для статусов лидов
['filter' => ['ENTITY_ID' => 'STATUS']]
```

**Использование:** для загрузки справочников стадий/статусов

**Документация:** https://context7.com/bitrix24/rest/crm.status.list

**Где используется:**
- `DealHandler::getDicts()` — стадии сделок
- `LeadHandler::getDicts()` — статусы лидов

**TTL кеша:** 86400 секунд (24 часа)

---

### crm.category.list

**Назначение:** Получение списка категорий

**Параметры:**
```php
// Для сделок
['entityTypeId' => 2]

// Для смарт-процессов
['entityTypeId' => (int) $entityTypeId]
```

**Использование:** для загрузки справочников категорий

**Документация:** https://context7.com/bitrix24/rest/crm.category.list

**Где используется:**
- `DealHandler::getDicts()` — категории сделок
- `SmartProcessHandler::getDicts()` — категории смарт-процесса

**TTL кеша:** 86400 секунд (24 часа)

---

### crm.type.list

**Назначение:** Получение списка типов смарт-процессов

**Параметры:**
```php
[]
```

**Использование:** для загрузки справочника типов смарт-процессов

**Документация:** https://context7.com/bitrix24/rest/crm.type.list

**Где используется:**
- `SmartProcessHandler::getDicts()`

**TTL кеша:** 86400 секунд (24 часа)

---

## Методы для анализа

### methods

**Назначение:** Получение списка всех доступных методов REST API

**Параметры:**
```php
[]
```

**Использование:** для анализа доступных событий и методов

**Документация:** https://context7.com/bitrix24/rest/methods

**Где используется:**
- `tools/allowed-events.php`

---

## Обработка ошибок API

### Типичные ошибки

1. **invalid_token** — неверный токен
   - **Причина:** токен неверный или истёк
   - **Действие:** проверка настройки `OUTGOING_WEBHOOK_TOKEN`

2. **NOT_FOUND** — сущность не найдена
   - **Причина:** сущность была удалена или ID неверный
   - **Действие:** проверка корректности `entityId`

3. **ACCESS_DENIED** — нет доступа
   - **Причина:** токен не имеет прав на метод
   - **Действие:** проверка прав токена в Bitrix24

4. **QUERY_LIMIT_EXCEEDED** — превышен лимит запросов
   - **Причина:** слишком много запросов в единицу времени
   - **Действие:** увеличение задержки между запросами

5. **INTERNAL_SERVER_ERROR** — внутренняя ошибка Bitrix24
   - **Причина:** временная проблема на стороне Bitrix24
   - **Действие:** повторная попытка (настраивается через `OUTGOING_WEBHOOK_REST_RETRIES`)

### Повторные попытки

**Настройки:**
- `OUTGOING_WEBHOOK_REST_RETRIES` — количество попыток (по умолчанию: `0`)
- `OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS` — задержка между попытками (по умолчанию: `0`)

**Логика:**
- При ошибке выполняется повтор с задержкой
- Если все попытки исчерпаны — ошибка логируется

---

## Сводная таблица методов

| Метод | Назначение | Где используется |
|-------|------------|------------------|
| `crm.deal.get` | Получение сделки | EnrichmentService, DealFileService |
| `crm.lead.get` | Получение лида | EnrichmentService |
| `crm.contact.get` | Получение контакта | EnrichmentService |
| `crm.company.get` | Получение компании | EnrichmentService |
| `crm.item.get` | Получение смарт-процесса | EnrichmentService |
| `crm.userfield.list` | Список пользовательских полей | EnrichmentService |
| `crm.deal.update` | Обновление сделки | DealFileService |
| `crm.status.list` | Список статусов/стадий | DealHandler, LeadHandler |
| `crm.category.list` | Список категорий | DealHandler, SmartProcessHandler |
| `crm.type.list` | Список типов смарт-процессов | SmartProcessHandler |
| `tasks.task.get` | Получение задачи | EnrichmentService, index.php |
| `task.item.getdata` | Данные задачи (CRM-связи) | TaskDetailsService |
| `task.item.getfiles` | Список файлов задачи | TaskFilesService |
| `task.commentitem.get` | Получение комментария | CommentDetailsService |
| `task.commentitem.getlist` | Список комментариев | CommentDetailsService |
| `tasks.task.files.attach` | Прикрепление файла к задаче | TaskFilesService |
| `im.dialog.messages.get` | Сообщения чата | CommentDetailsService |
| `disk.file.get` | Информация о файле | TaskFilesService, DealFileService |
| `user.get` | Получение пользователя | EnrichmentService |
| `sonet_group.get` | Получение проекта | EnrichmentService |
| `methods` | Список методов API | allowed-events.php |

---

## Связанные документы

- `07-enrichment-services.md` — сервисы обогащения (маппинг методов)
- `09-task-services.md` — сервисы задач (использование методов)
- `06-core-services.md` — базовые сервисы (RestService)

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием интеграции с Bitrix24 REST API.
