# Task Services: сервисы работы с задачами и комментариями

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает сервисы, отвечающие за работу с задачами и комментариями: извлечение деталей, форматирование, работа с файлами, обработка ActivityFirst, синхронная обработка комментариев.

---

## TaskDetailsService

**Файл:** `outgoing-webhook/services/Task/TaskDetailsService.php`

### Назначение

Сервис для работы с деталями задач: извлечение данных, построение деталей, форматирование для логов, работа с CRM-связями, оценка ActivityFirst.

### Зависимости

- `FilesystemService` — для записи логов
- `RequestService` — для работы с данными и временем
- `LogValueFormatter` — для форматирования значений

### Методы

#### `writeDetails(string $eventType, array $enriched, ?string $requestId, ?string $entityId): ?array`

Запись деталей задачи в лог.

**Параметры:**
- `$eventType` — тип события
- `$enriched` — обогащённые данные
- `$requestId` — ID запроса
- `$entityId` — ID задачи

**Возвращает:** данные задачи или `null`

**Алгоритм:**
1. Извлечение данных задачи из `enriched`
2. Построение деталей через `buildDetails()`
3. Запись в `task-details.log`

#### `extractTaskData(array $enriched): ?array`

Извлечение данных задачи из обогащённых данных.

**Параметры:**
- `$enriched` — обогащённые данные

**Возвращает:** данные задачи или `null`

**Приоритет источников:**
1. `enriched.data.task.task` (вложенная структура)
2. `enriched.data.task` (плоская структура)

#### `buildDetails(array $taskData, string $eventType, ?string $requestId, ?string $entityId): array`

Построение деталей задачи.

**Параметры:**
- `$taskData` — данные задачи
- `$eventType` — тип события
- `$requestId` — ID запроса
- `$entityId` — ID задачи

**Возвращает:** массив деталей задачи

**Формат результата:**
```json
{
  "loggedAt": "2026-01-26T12:00:00+03:00",
  "requestId": "abc123...",
  "eventType": "ONTASKADD",
  "taskId": "123",
  "title": "Task title",
  "createdBy": "456",
  "groupId": "15",
  "deadline": "2026-01-30T00:00:00+03:00",
  "startDatePlan": "2026-01-27T00:00:00+03:00",
  "endDatePlan": "2026-01-29T00:00:00+03:00"
}
```

**Извлекаемые поля:**
- `taskId` — ID задачи (`ID`, `id`)
- `title` — название (`TITLE`, `NAME`, `title`)
- `createdBy` — постановщик (`CREATED_BY`, `CREATED_BY_ID`, `createdBy`, `creator.id`)
- `groupId` — проект (`GROUP_ID`, `PROJECT_ID`, `groupId`, `group.id`)
- `deadline` — срок (`DEADLINE`, `deadline`)
- `startDatePlan` — плановая дата начала (`START_DATE_PLAN`, `startDatePlan`)
- `endDatePlan` — плановая дата окончания (`END_DATE_PLAN`, `endDatePlan`)

#### `formatDetailsRu(array $details): string`

Форматирование деталей задачи для логов.

**Параметры:**
- `$details` — детали задачи

**Возвращает:** форматированная строка

**Формат:**
```
Дата={loggedAt} | requestId={requestId} | Событие={eventType} | Задача={taskId} | Название={title} | Постановщик={createdBy} | Проект={groupId} | Срок={deadline} | ПланСтарт={startDatePlan} | ПланФиниш={endDatePlan}
```

#### `writeDetailsRu(string $eventType, array $details): void`

Запись деталей задачи в лог.

**Параметры:**
- `$eventType` — тип события
- `$details` — детали задачи

**Файл:** `logs/{EVENT_TYPE}/task-details.log`

**Примечание:** запись выполняется только для событий, начинающихся с `ONTASK`

#### `extractMeta(?array $taskData): array`

Извлечение метаданных задачи (проект, CRM-связи).

**Параметры:**
- `$taskData` — данные задачи

**Возвращает:** массив метаданных

**Формат результата:**
```json
{
  "projectId": "15",
  "projectName": "Project name",
  "crmLinks": ["D_123", "L_456"]
}
```

**Извлекаемые поля:**
- `projectId` — ID проекта (`GROUP_ID`, `groupId`, `group.id`)
- `projectName` — название проекта (`GROUP_NAME`, `group.name`)
- `crmLinks` — CRM-связи (`UF_CRM_TASK`)

#### `loadActivityFirstConditions(): array`

Загрузка условий ActivityFirst из файла.

**Возвращает:** массив условий

**Файл:** `activity/first/conditions.php`

**Формат файла:**
```php
<?php
return [
    'projectId' => '15',
    'crmDealPrefix' => 'D_',
    'keywords' => ['обложка', 'Обложка', 'ОБЛОЖКА'],
];
```

**Значения по умолчанию:**
```php
[
    'projectId' => null,
    'crmDealPrefix' => 'D_',
    'keywords' => [],
]
```

#### `messageHasKeyword(string $message, array $keywords): bool`

Проверка наличия ключевого слова в сообщении.

**Параметры:**
- `$message` — текст сообщения
- `$keywords` — массив ключевых слов

**Возвращает:** `true` если найдено ключевое слово

**Особенности:**
- Регистронезависимый поиск (через `mb_stripos()` или `stripos()`)
- Поиск подстроки (не точное совпадение)

#### `hasDealLink(array $crmLinks, string $dealPrefix): bool`

Проверка наличия ссылки на сделку в CRM-связях.

**Параметры:**
- `$crmLinks` — массив CRM-связей
- `$dealPrefix` — префикс сделки (например, `'D_'`)

**Возвращает:** `true` если найдена ссылка на сделку

**Проверка:**
1. Префикс `D_` (например, `'D_123'`)
2. URL `/crm/deal/` в ссылке

#### `evaluateActivityFirst(array $details): bool`

Оценка условий ActivityFirst.

**Параметры:**
- `$details` — детали комментария/задачи

**Возвращает:** `true` если условия выполнены

**Условия:**
1. Проект совпадает (если задан в условиях)
2. Есть ссылка на сделку в CRM-связях
3. Сообщение содержит ключевое слово
4. Есть прикреплённые файлы

**Все условия должны быть выполнены одновременно.**

#### `loadCrmLinks(string $taskId, callable $restCall): array`

Загрузка CRM-связей задачи через REST API.

**Параметры:**
- `$taskId` — ID задачи
- `$restCall` — функция для вызова REST API

**Возвращает:** массив CRM-связей

**Метод REST API:** `task.item.getdata`

**Поле:** `UF_CRM_TASK`

#### `ensureCrmLinks(?array $taskData, string $taskId, callable $restCall): ?array`

Обеспечение наличия CRM-связей в данных задачи.

**Параметры:**
- `$taskData` — данные задачи
- `$taskId` — ID задачи
- `$restCall` — функция для вызова REST API

**Возвращает:** данные задачи с CRM-связями

**Алгоритм:**
1. Если `UF_CRM_TASK` уже есть — возврат данных
2. Иначе — загрузка через `loadCrmLinks()`
3. Добавление `UF_CRM_TASK` в данные задачи

#### `extractDealIds(array $crmLinks): array`

Извлечение ID сделок из CRM-связей.

**Параметры:**
- `$crmLinks` — массив CRM-связей

**Возвращает:** массив ID сделок

**Формат связей:**
- Префикс `D_` → `D_123` → `123`
- URL `/crm/deal/details/123/` → `123`

#### `logActivityFirst(array $entry): void`

Логирование ActivityFirst.

**Параметры:**
- `$entry` — запись для логирования

**Файл:** `logs/activity-first.log`

**Формат записи:** line-based JSON

#### `markActivityFirstProcessed(string $requestId, string $taskId): void`

Маркировка события как синхронно обработанного.

**Параметры:**
- `$requestId` — ID запроса
- `$taskId` — ID задачи

**Файл:** `state/activity-first-processed/{requestId}_{taskId}.json`

**Использование:** для предотвращения повторной обработки в очереди

#### `isActivityFirstProcessed(string $requestId, string $taskId): bool`

Проверка, было ли событие уже обработано синхронно.

**Параметры:**
- `$requestId` — ID запроса
- `$taskId` — ID задачи

**Возвращает:** `true` если уже обработано

---

## TaskFilesService

**Файл:** `outgoing-webhook/services/Task/TaskFilesService.php`

### Назначение

Сервис для работы с файлами задач: получение прикреплённых файлов, прикрепление файлов к задаче, получение информации о файле из Disk.

### Методы

#### `getAttachedFiles(string $taskId, callable $restCall): array`

Получение списка прикреплённых файлов задачи.

**Параметры:**
- `$taskId` — ID задачи
- `$restCall` — функция для вызова REST API

**Возвращает:** массив ID файлов

**Метод REST API:** `task.item.getfiles`

**Пример:**
```php
$fileIds = $taskFiles->getAttachedFiles('123', $restCall);
// Результат: ['456', '789']
```

#### `attachFiles(string $taskId, array $fileIds, callable $restCall): array`

Прикрепление файлов к задаче.

**Параметры:**
- `$taskId` — ID задачи
- `$fileIds` — массив ID файлов для прикрепления
- `$restCall` — функция для вызова REST API

**Возвращает:** массив с результатами

**Формат результата:**
```json
{
  "attached": ["456", "789"],
  "errors": [
    {"fileId": "999", "error": "file_not_found"}
  ]
}
```

**Алгоритм:**
1. Получение существующих файлов задачи
2. Для каждого файла:
   - Пропуск, если уже прикреплён
   - Прикрепление через `tasks.task.files.attach`
   - Обработка ошибок

**Метод REST API:** `tasks.task.files.attach`

#### `getDiskFileInfo(string $fileId, callable $restCall): ?array`

Получение информации о файле из Bitrix24 Disk.

**Параметры:**
- `$fileId` — ID файла
- `$restCall` — функция для вызова REST API

**Возвращает:** данные файла или `null`

**Метод REST API:** `disk.file.get`

**Использование:** для получения `downloadUrl` файла

---

## DealFileService

**Файл:** `outgoing-webhook/services/Crm/DealFileService.php`

### Назначение

Сервис для работы с файлами сделок: построение данных файла, получение файлового поля сделки, обновление файлов сделки.

### Зависимости

- `FilesystemService` — для загрузки файлов
- `RequestService` — для разрешения URL
- `TaskFilesService` — для получения информации о файлах

### Методы

#### `buildDealFileData(string $fileId, callable $restCall): ?array`

Построение данных файла для сделки.

**Параметры:**
- `$fileId` — ID файла
- `$restCall` — функция для вызова REST API

**Возвращает:** массив `[name, base64]` или `null`

**Алгоритм:**
1. Получение информации о файле через `TaskFilesService::getDiskFileInfo()`
2. Получение `downloadUrl` из информации
3. Разрешение абсолютного URL через `RequestService::resolveAbsoluteUrl()`
4. Загрузка файла в Base64 через `FilesystemService::downloadBase64()`
5. Возврат `[имя_файла, base64_данные]`

**Использование:** для подготовки файла к добавлению в сделку

#### `getDealFileField(string $dealId, string $field, callable $restCall): array`

Получение файлового поля сделки.

**Параметры:**
- `$dealId` — ID сделки
- `$field` — имя поля (например, `'UF_CRM_123'`)
- `$restCall` — функция для вызова REST API

**Возвращает:** массив записей файлов

**Метод REST API:** `crm.deal.get`

**Формат результата:**
```json
[
  {
    "id": "456",
    "downloadUrl": "https://...",
    "showUrl": "https://..."
  }
]
```

#### `buildFromDealEntry(array $entry): ?array`

Построение данных файла из записи сделки.

**Параметры:**
- `$entry` — запись файла из сделки

**Возвращает:** массив `[name, base64]` или `null`

**Алгоритм:**
1. Получение `downloadUrl` из записи
2. Разрешение абсолютного URL
3. Загрузка файла в Base64
4. Извлечение имени файла из URL или генерация по ID

#### `updateDealFiles(string $dealId, string $field, array $fileDataList, callable $restCall): array`

Обновление файлового поля сделки.

**Параметры:**
- `$dealId` — ID сделки
- `$field` — имя поля
- `$fileDataList` — массив новых файлов (формат: `[['fileData' => [name, base64]]]`)
- `$restCall` — функция для вызова REST API

**Возвращает:** массив с результатом

**Формат результата:**
```json
{
  "success": true,
  "fileErrors": [
    {"fileId": "456", "error": "failed_to_load_existing_file"}
  ]
}
```

**Алгоритм:**
1. Получение существующих файлов через `getDealFileField()`
2. Построение данных для всех существующих файлов
3. Добавление новых файлов из `$fileDataList`
4. Обновление сделки через `crm.deal.update`

**Метод REST API:** `crm.deal.update`

**Важно:** все существующие файлы должны быть включены в обновление, иначе они будут удалены.

---

## CommentDetailsService

**Файл:** `outgoing-webhook/services/Task/CommentDetailsService.php`

### Назначение

Сервис для работы с деталями комментариев: получение данных комментария, построение деталей, форматирование, обработка ActivityFirst, синхронная обработка.

### Зависимости

- `RestService` (опционально) — для REST-запросов
- `ErrorService` — для логирования ошибок
- `TaskDetailsService` — для работы с задачами
- `TaskFilesService` — для работы с файлами
- `DealFileService` — для работы с файлами сделок
- `EntityIdentityService` — для извлечения ID
- `RequestService` — для работы с данными
- `LogValueFormatter` — для форматирования
- `FilesystemService` — для записи файлов
- `ConfigService` — для конфигурации

### Методы

#### `handleCommentAdd(string $eventType, array $raw, array $job, ?string $entityId, string $rawPath, ?array $taskData): void`

Обработка события добавления комментария.

**Параметры:**
- `$eventType` — тип события
- `$raw` — сырые данные
- `$job` — задание очереди
- `$entityId` — ID задачи
- `$rawPath` — путь к `raw.json`
- `$taskData` — данные задачи

**Алгоритм:**
1. Извлечение `commentId` и `messageId`
2. Получение данных комментария через `fetchDetails()` или `fetchChatMessageDetails()`
3. Построение деталей комментария
4. Запись в `comment-details.log`
5. Запись обогащённых данных (если нужно)
6. Обработка ActivityFirst (если условия выполнены)

#### `buildDetails(array $commentData, string $eventType, ?string $requestId, ?string $taskId, ?string $commentId, ?string $sourceMethod, ?array $taskData = null): array`

Построение деталей комментария.

**Параметры:**
- `$commentData` — данные комментария
- `$eventType` — тип события
- `$requestId` — ID запроса
- `$taskId` — ID задачи
- `$commentId` — ID комментария
- `$sourceMethod` — метод получения данных
- `$taskData` — данные задачи (опционально)

**Возвращает:** массив деталей комментария

**Формат результата:**
```json
{
  "loggedAt": "2026-01-26T12:00:00+03:00",
  "requestId": "abc123...",
  "eventType": "ONTASKCOMMENTADD",
  "taskId": "123",
  "commentId": "456",
  "authorId": "789",
  "message": "Comment text",
  "createdAt": "2026-01-26T12:00:00+03:00",
  "sourceMethod": "task.commentitem.get",
  "projectId": "15",
  "projectName": "Project name",
  "crmLinks": ["D_123"],
  "commentKind": "user",
  "activityFirst": true,
  "fileIds": ["999"]
}
```

**Извлекаемые поля:**
- `commentId` — ID комментария
- `authorId` — ID автора
- `message` — текст комментария
- `createdAt` — дата создания
- `commentKind` — тип комментария (`'user'` или `'system'`)

**Оценка ActivityFirst:** выполняется через `TaskDetailsService::evaluateActivityFirst()`

#### `fetchDetails(callable $restCall, string $taskId, string $commentId): array`

Получение данных комментария через REST API.

**Параметры:**
- `$restCall` — функция для вызова REST API
- `$taskId` — ID задачи
- `$commentId` — ID комментария

**Возвращает:** массив `['data' => array|null, 'method' => string, 'errors' => array]`

**Алгоритм (множественные попытки):**
1. `task.commentitem.get` с `taskId` и `itemId`
2. `task.commentitem.getlist` с фильтром по ID
3. `task.commentitem.getlist` без фильтра (последние комментарии)
4. `task.commentitem.getlist` с фильтром по дате (последние 10 минут)
5. `task.commentitem.getlist` с альтернативными параметрами

**При успехе:** возврат данных комментария и метода

**При ошибке:** возврат `null` и массива ошибок

#### `fetchChatMessageDetails(callable $restCall, string $chatId, string $messageId): array`

Получение данных комментария через чат (fallback).

**Параметры:**
- `$restCall` — функция для вызова REST API
- `$chatId` — ID чата
- `$messageId` — ID сообщения

**Возвращает:** массив `['data' => array|null, 'method' => string]`

**Метод REST API:** `im.dialog.messages.get`

**Использование:** когда комментарий не найден через `task.commentitem.get`

#### `processActivityFirst(array $commentDetails, string $taskId, callable $restCall): array`

Синхронная обработка ActivityFirst.

**Параметры:**
- `$commentDetails` — детали комментария
- `$taskId` — ID задачи
- `$restCall` — функция для вызова REST API

**Возвращает:** массив с результатами обработки

**Формат результата:**
```json
{
  "dealIds": ["123", "456"],
  "fileIds": ["789", "999"],
  "taskAttach": {
    "attached": ["789", "999"],
    "errors": []
  },
  "dealUpdates": [
    {"dealId": "123", "success": true},
    {"dealId": "456", "success": true}
  ]
}
```

**Алгоритм:**
1. Извлечение `fileIds` и `dealIds` из деталей комментария
2. Прикрепление файлов к задаче через `TaskFilesService::attachFiles()`
3. Для каждой сделки:
   - Получение файлового поля сделки
   - Построение данных новых файлов
   - Обновление файлового поля через `DealFileService::updateDealFiles()`
4. Возврат результатов

**Использование:** вызывается синхронно в `index.php` после отправки ответа Bitrix24

#### `shouldWriteEnriched(?string $taskId): bool`

Проверка необходимости записи обогащённых данных комментария.

**Параметры:**
- `$taskId` — ID задачи

**Возвращает:** `true` если нужно записать

**Условие:** `OUTGOING_WEBHOOK_COMMENT_ENRICHED_TASK_ID` должен совпадать с `$taskId`

**Использование:** для ограничения записи обогащённых данных только для определённой задачи

---

## Процесс обработки комментария

### 1. Получение данных комментария

```php
$fetch = $commentDetails->fetchDetails($restCall, $taskId, $commentId);
if (is_array($fetch['data'])) {
    $commentData = $fetch['data'];
    $sourceMethod = $fetch['method'];
} else {
    // Fallback на чат
    $chatFetch = $commentDetails->fetchChatMessageDetails($restCall, $chatId, $messageId);
}
```

### 2. Построение деталей

```php
$details = $commentDetails->buildDetails(
    $commentData,
    $eventType,
    $requestId,
    $taskId,
    $commentId,
    $sourceMethod,
    $taskData
);
```

### 3. Запись в лог

```php
$commentDetails->writeDetailsRu($eventType, $details);
```

### 4. Обработка ActivityFirst

```php
if (!empty($details['activityFirst'])) {
    $result = $commentDetails->processActivityFirst($details, $taskId, $restCall);
    // Прикрепление файлов к задаче
    // Обновление файлов сделок
}
```

---

## Связанные документы

- `05-services-architecture.md` — общая архитектура сервисов
- `06-core-services.md` — базовые сервисы
- `07-enrichment-services.md` — сервисы обогащения
- `13-activity-first-sync.md` — синхронная обработка ActivityFirst
- `04-activity-first.md` — логика ActivityFirst

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием Task Services.
