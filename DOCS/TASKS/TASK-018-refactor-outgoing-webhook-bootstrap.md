# TASK-018: Рефакторинг outgoing-webhook/bootstrap.php (декомпозиция и shim-совместимость)

**Дата создания:** 2026-01-23 22:10 (UTC+03:00, Брест)  
**Статус:** draft  
**Приоритет:** Средний  
**Исполнитель:** Bitrix24 Программист  

## Цель
Декомпозировать `outgoing-webhook/bootstrap.php` на специализированные сервисы и
оставить в файле только тонкие shim‑обёртки, сохранив поведение и контракты.
Рефакторинг должен следовать принципам `TASK-017` (вынос логики в сервисы,
backward‑compatibility).

## Контекст
После выполнения `TASK-017` обработчик очереди вынесен в сервисы, однако
`outgoing-webhook/bootstrap.php` всё ещё содержит большой объём бизнес‑логики:
парсинг payload, извлечение идентификаторов, сбор деталей задач/комментариев,
работа с файлами и базовые утилиты. Это усложняет сопровождение и тестирование.

Цель — превратить `bootstrap.php` в точку входа с shim‑функциями и вынести
доменную логику в сервисный слой, сохранив поведение и форматы логов/JSON.

## Объём и границы
- Рефакторинг без изменений поведения по умолчанию.
- Улучшения допустимы только по согласованию.
- Все внешние вызовы функций/констант из `bootstrap.php` должны остаться рабочими.
- Форматы логов/JSON не менять.

## Модули и компоненты
### Файлы к рефакторингу
- `outgoing-webhook/bootstrap.php` — оставить shim‑функции и минимальную оркестрацию.

### Обязательные сервисы (полный доменный набор)
- `outgoing-webhook/services/Config/ConfigService.php` — единый доступ к env/config.local.php.
- `outgoing-webhook/services/Core/FilesystemService.php` — mkdir/writeJson/appendLine/скачивание.
- `outgoing-webhook/services/Http/RequestService.php` — чтение payload + JSON‑ответы.
- `outgoing-webhook/services/Security/AccessService.php` — allowed IPs + auth token/info.
- `outgoing-webhook/services/Logging/ErrorService.php` — ошибки (использовать существующий).
- `outgoing-webhook/services/Logging/LogValueFormatter.php` — normalize/mask (если нужно).
- `outgoing-webhook/services/Identity/EntityIdentityService.php` — extract/normalize ID + entity type.
- `outgoing-webhook/services/Task/TaskDetailsService.php` — детали задач + activity-first.
- `outgoing-webhook/services/Task/TaskFilesService.php` — attach/read task files.
- `outgoing-webhook/services/Task/CommentDetailsService.php` — детали комментариев.
- `outgoing-webhook/services/Crm/DealFileService.php` — работа с deal file fields.
- `outgoing-webhook/services/Rest/RestService.php` — единый слой REST (как в TASK-017).

> Требование: TaskDetailsService и CommentDetailsService — строго раздельные.

## Зависимости
- `TASK-017-refactor-outgoing-webhook-process-services.md` — архитектурные принципы и backward‑compatibility.
- `app/Services/Bitrix24Client.php`, `app/crest.php` — REST‑вызовы Bitrix24.
- Форматы логов и payload:
  - `DOCS/TASKS/TASK-014-06-payload-schemas.md`
  - `DOCS/LOGS-Managment/outgoing-webhook-logs.md`

## Требования
### Функциональные
- Вынести бизнес‑логику из `bootstrap.php` в доменные сервисы.
- Сохранить доступность всех shim‑функций и констант.
- Описать allowed IPs и auth token/info явно.
- Ветка комментариев через `im.*` должна быть сохранена и описана.

### Нефункциональные
- PHP 8.4, PSR‑12.
- Форматы `raw.json`, `enriched.json`, `event.log`, `task-details.log`,
  `comment-details.log` не изменяются.
- Ошибки пишутся в `outgoing-webhook/logs/errors/` с прежними ключами
  (минимум: `loggedAt`, `message`, `context`).
- Использовать `RestService` как единственный REST‑слой.
- Метрики/тайминги — опционально, если не усложняют.

## Совместимость и shim‑контракты
- Полная API‑совместимость функций/констант из `bootstrap.php`.
- Внешние скрипты, которые `require bootstrap.php`, работают без правок.
- Полный список shim‑функций/констант с маппингом на сервисы — обязателен.

## Ступенчатые подзадачи
1. Зафиксировать текущий список функций/констант в `bootstrap.php`, сгруппировать по доменам
   и добавить полный список shim‑функций с маппингом на сервисы.
2. Обозначить критичные точки совместимости (все внешние вызовы функций/констант).
3. Зафиксировать формат ошибок `logs/errors/` (ключи/структура).
4. Уточнить спецификацию по allowed IPs и auth token/info.
5. Зафиксировать политику изменений: по умолчанию строгий рефакторинг без изменения поведения.
6. Описать отдельную ветку логики комментариев через чат (`im.*`).
7. Зафиксировать использование `RestService` как единого REST‑слоя.
8. Опционально: описать метрики/тайминги выполнения (если решено добавлять).
9. Выполнить перенос логики по доменам:
   - конфигурация/окружение,
   - файловая система/логирование,
   - безопасность и авторизация,
   - парсинг payload и извлечение ID,
   - задачи/комментарии,
   - CRM/файлы/диски.
10. Определить целевые сервисы и распределить функции по ним.
11. Реализовать сервисы, сохранив существующее поведение и форматы выходных данных.
12. Перенести логику из `bootstrap.php` в сервисы:
   - `outgoingWebhookReadPayload`, `outgoingWebhookJsonResponse` → `RequestService`.
   - `outgoingWebhookGetEnv`, `outgoingWebhookGetConfig`, `outgoingWebhookGetSetting` → `ConfigService`.
   - `outgoingWebhookSafeMkdir`, `outgoingWebhookWriteJson`, `outgoingWebhookAppendLine`,
     `outgoingWebhookDownloadBase64FromUrl` → `FilesystemService`.
   - `outgoingWebhookLogError` → `ErrorService` (с сохранением формата).
   - `outgoingWebhookMask*`, `outgoingWebhookNormalizeLogValue` → `LogValueFormatter`.
   - `outgoingWebhookGetAllowedIps`, `outgoingWebhookExtractAuth*` → `AccessService`.
   - `outgoingWebhookExtract*Id`, `outgoingWebhookNormalize*`, `outgoingWebhookResolveEntityType`
     → `EntityIdentityService`.
   - `outgoingWebhookBuildTaskDetails*`, `outgoingWebhookWriteTaskDetailsRu`,
     `outgoingWebhookLoadActivityFirstConditions`, `outgoingWebhookEvaluateActivityFirst`,
     `outgoingWebhookLogActivityFirst` → `TaskDetailsService`.
   - `outgoingWebhookBuildCommentDetails*`, `outgoingWebhookWriteCommentDetailsRu`,
     `outgoingWebhookFetchCommentDetails`, `outgoingWebhookFetchChatMessageDetails`
     → `CommentDetailsService`.
   - `outgoingWebhookGetTaskAttachedFiles`, `outgoingWebhookAttachFilesToTask`,
     `outgoingWebhookGetDiskFileInfo` → `TaskFilesService`.
   - `outgoingWebhookBuildDealFileData*`, `outgoingWebhookGetDealFileField`,
     `outgoingWebhookUpdateDealFiles` → `DealFileService`.
13. Оставить в `bootstrap.php` shim‑функции с прежними именами и сигнатурами,
    проксирующие вызовы в сервисы (без изменения поведения).
14. Обновить документацию по структуре `outgoing-webhook/bootstrap/` и сервисов
    в `DOCS/OUTGOING-EVENTS/` (обязательно).
15. Добавить unit‑тесты для ключевых сервисов (минимум для критичных).

## API-методы (если применимо)
Методы Bitrix24 REST, используемые внутри сервисов:
- `task.item.getdata` — https://context7.com/bitrix24/rest/task.item.getdata
- `task.commentitem.get` — https://context7.com/bitrix24/rest/task.commentitem.get
- `disk.file.get` — https://context7.com/bitrix24/rest/disk.file.get
- `crm.deal.get` — https://context7.com/bitrix24/rest/crm.deal.get
- `im.chat.get` — https://context7.com/bitrix24/rest/im.chat.get
- `im.message.get` — https://context7.com/bitrix24/rest/im.message.get

## Полный список shim‑функций/констант (таблица маппинга)
| Shim‑функция/константа | Сервис | Метод сервиса (предлагаемый API) | Статус | Комментарий |
| --- | --- | --- | --- | --- |
| `OUTGOING_WEBHOOK_MAX_BYTES` | `ConfigService` | `MAX_BYTES` или `getMaxBytes()` | под вопросом | Лимит payload |
| `outgoingWebhookGetEnv()` | `ConfigService` | `getEnv()` | стабильно | Env‑доступ |
| `outgoingWebhookGetConfig()` | `ConfigService` | `getConfig()` | стабильно | `config.local.php` |
| `outgoingWebhookGetSetting()` | `ConfigService` | `get()` | стабильно | Настройки |
| `outgoingWebhookGetClientEndpoint()` | `ConfigService` | `getClientEndpoint()` | стабильно | URL |
| `outgoingWebhookSafeMkdir()` | `FilesystemService` | `ensureDir()` | стабильно | Файлы |
| `outgoingWebhookWriteJson()` | `FilesystemService` | `writeJson()` | стабильно | Файлы |
| `outgoingWebhookAppendLine()` | `FilesystemService` | `appendLine()` | стабильно | Логи |
| `outgoingWebhookDownloadBase64FromUrl()` | `FilesystemService` | `downloadBase64()` | стабильно | Файлы |
| `outgoingWebhookReadPayload()` | `RequestService` | `readPayload()` | стабильно | HTTP |
| `outgoingWebhookJsonResponse()` | `RequestService` | `jsonResponse()` | стабильно | HTTP |
| `outgoingWebhookNormalizeEventType()` | `RequestService` | `normalizeEventType()` | стабильно | HTTP |
| `outgoingWebhookResolveAbsoluteUrl()` | `RequestService` | `resolveAbsoluteUrl()` | под вопросом | Может жить в Config/Url service |
| `outgoingWebhookGetFirstValue()` | `RequestService` | `getFirstValue()` | под вопросом | Утилита, может быть Helper |
| `outgoingWebhookGenerateRequestId()` | `RequestService` | `generateRequestId()` | под вопросом | Утилита |
| `outgoingWebhookNow()` | `RequestService` | `now()` | под вопросом | Время, возможно Clock |
| `outgoingWebhookGetAllowedIps()` | `AccessService` | `getAllowedIps()` | стабильно | Безопасность |
| `outgoingWebhookExtractAuthToken()` | `AccessService` | `extractAuthToken()` | стабильно | Безопасность |
| `outgoingWebhookExtractAuthInfo()` | `AccessService` | `extractAuthInfo()` | стабильно | Безопасность |
| `outgoingWebhookLogError()` | `ErrorService` | `log()` | стабильно | Ошибки |
| `outgoingWebhookMaskValue()` | `LogValueFormatter` | `maskValue()` | стабильно | Логи |
| `outgoingWebhookMaskPayload()` | `LogValueFormatter` | `maskPayload()` | стабильно | Логи |
| `outgoingWebhookNormalizeLogValue()` | `LogValueFormatter` | `normalize()` | стабильно | Логи |
| `outgoingWebhookExtractEntityId()` | `EntityIdentityService` | `extractEntityId()` | стабильно | Identity |
| `outgoingWebhookExtractCommentId()` | `EntityIdentityService` | `extractCommentId()` | стабильно | Identity |
| `outgoingWebhookNormalizeEntityId()` | `EntityIdentityService` | `normalizeEntityId()` | стабильно | Identity |
| `outgoingWebhookExtractTaskId()` | `EntityIdentityService` | `extractTaskId()` | стабильно | Identity |
| `outgoingWebhookExtractMessageId()` | `EntityIdentityService` | `extractMessageId()` | стабильно | Identity |
| `outgoingWebhookResolveEntityType()` | `EntityIdentityService` | `resolveEntityType()` | стабильно | Identity |
| `outgoingWebhookExtractTaskData()` | `TaskDetailsService` | `extractTaskData()` | стабильно | Task |
| `outgoingWebhookBuildTaskDetails()` | `TaskDetailsService` | `buildDetails()` | стабильно | Task |
| `outgoingWebhookFormatTaskDetailsRu()` | `TaskDetailsService` | `formatDetailsRu()` | стабильно | Task |
| `outgoingWebhookWriteTaskDetailsRu()` | `TaskDetailsService` | `writeDetailsRu()` | стабильно | Task |
| `outgoingWebhookExtractTaskMeta()` | `TaskDetailsService` | `extractMeta()` | стабильно | Task |
| `outgoingWebhookLoadActivityFirstConditions()` | `TaskDetailsService` | `loadActivityFirstConditions()` | стабильно | Task |
| `outgoingWebhookMessageHasKeyword()` | `TaskDetailsService` | `messageHasKeyword()` | стабильно | Task |
| `outgoingWebhookHasDealLink()` | `TaskDetailsService` | `hasDealLink()` | стабильно | Task |
| `outgoingWebhookEvaluateActivityFirst()` | `TaskDetailsService` | `evaluateActivityFirst()` | стабильно | Task |
| `outgoingWebhookLoadTaskCrmLinks()` | `TaskDetailsService` | `loadCrmLinks()` | стабильно | Task |
| `outgoingWebhookEnsureTaskCrmLinks()` | `TaskDetailsService` | `ensureCrmLinks()` | стабильно | Task |
| `outgoingWebhookExtractDealIds()` | `TaskDetailsService` | `extractDealIds()` | стабильно | Task |
| `outgoingWebhookLogActivityFirst()` | `TaskDetailsService` | `logActivityFirst()` | стабильно | Task |
| `outgoingWebhookGetTaskAttachedFiles()` | `TaskFilesService` | `getAttachedFiles()` | стабильно | Task files |
| `outgoingWebhookAttachFilesToTask()` | `TaskFilesService` | `attachFiles()` | стабильно | Task files |
| `outgoingWebhookGetDiskFileInfo()` | `TaskFilesService` | `getDiskFileInfo()` | стабильно | Task files |
| `outgoingWebhookBuildDealFileData()` | `DealFileService` | `buildDealFileData()` | стабильно | CRM files |
| `outgoingWebhookGetDealFileField()` | `DealFileService` | `getDealFileField()` | стабильно | CRM files |
| `outgoingWebhookBuildDealFileDataFromDealEntry()` | `DealFileService` | `buildFromDealEntry()` | стабильно | CRM files |
| `outgoingWebhookUpdateDealFiles()` | `DealFileService` | `updateDealFiles()` | стабильно | CRM files |
| `outgoingWebhookBuildCommentDetails()` | `CommentDetailsService` | `buildDetails()` | стабильно | Comment |
| `outgoingWebhookBuildCommentFallback()` | `CommentDetailsService` | `buildFallback()` | стабильно | Comment |
| `outgoingWebhookResolveCommentKind()` | `CommentDetailsService` | `resolveKind()` | стабильно | Comment |
| `outgoingWebhookFormatCommentDetailsRu()` | `CommentDetailsService` | `formatDetailsRu()` | стабильно | Comment |
| `outgoingWebhookWriteCommentDetailsRu()` | `CommentDetailsService` | `writeDetailsRu()` | стабильно | Comment |
| `outgoingWebhookFindCommentItem()` | `CommentDetailsService` | `findCommentItem()` | стабильно | Comment |
| `outgoingWebhookFetchCommentDetails()` | `CommentDetailsService` | `fetchDetails()` | стабильно | Comment |
| `outgoingWebhookExtractChatId()` | `CommentDetailsService` | `extractChatId()` | стабильно | `im.*` |
| `outgoingWebhookFindChatMessage()` | `CommentDetailsService` | `findChatMessage()` | стабильно | `im.*` |
| `outgoingWebhookFetchChatMessageDetails()` | `CommentDetailsService` | `fetchChatMessageDetails()` | стабильно | `im.*` |
| `outgoingWebhookBuildCommentDetailsFromChat()` | `CommentDetailsService` | `buildDetailsFromChat()` | стабильно | `im.*` |
| `outgoingWebhookWriteCommentEnriched()` | `CommentDetailsService` | `writeEnriched()` | стабильно | Comment |
| `outgoingWebhookShouldWriteCommentEnriched()` | `CommentDetailsService` | `shouldWriteEnriched()` | стабильно | Comment |

## API сервисов (сигнатуры)
> `callable $restCall` во всех сервисах должен использовать `RestService::call()`.

### ConfigService
- `getEnv(string $key, ?string $default = null): ?string`
- `getConfig(): array`
- `get(string $key, ?string $default = null): ?string`
- `getClientEndpoint(): ?string`
- `getMaxBytes(): int` или константа `MAX_BYTES`

### FilesystemService
- `ensureDir(string $path): void`
- `writeJson(string $path, array $data): bool`
- `appendLine(string $path, string $line): bool`
- `downloadBase64(string $url): ?string`

### RequestService
- `readPayload(): array`
- `jsonResponse(int $statusCode, array $payload): void`
- `normalizeEventType(?string $event): string`
- `resolveAbsoluteUrl(string $url): string`
- `getFirstValue(array $data, array $keys)`
- `generateRequestId(): string`
- `now(): string`

### AccessService
- `getAllowedIps(): array`
- `extractAuthToken(array $payload): string`
- `extractAuthInfo(array $payload): array` (ключи: `token`, `source`)

### ErrorService
- `log(string $message, array $context = []): void`

### LogValueFormatter
- `maskValue(string $value): string`
- `maskPayload(array $payload): array`
- `normalize($value): string`

### EntityIdentityService
- `extractEntityId(array $payload): ?string`
- `extractCommentId(array $payload): ?string`
- `normalizeEntityId(?string $entityId): ?string`
- `extractTaskId(array $payload): ?string`
- `extractMessageId(array $payload): ?string`
- `resolveEntityType(string $eventType): string`

### TaskDetailsService
- `extractTaskData(array $enriched): ?array`
- `buildDetails(array $taskData, string $eventType, ?string $requestId, ?string $entityId): array`
- `formatDetailsRu(array $details): string`
- `writeDetailsRu(string $eventType, array $details): void`
- `extractMeta(?array $taskData): array` (ключи: `projectId`, `projectName`, `crmLinks`)
- `loadActivityFirstConditions(): array`
- `messageHasKeyword(string $message, array $keywords): bool`
- `hasDealLink(array $crmLinks, string $dealPrefix): bool`
- `evaluateActivityFirst(array $details): bool`
- `loadCrmLinks(string $taskId, callable $restCall): array`
- `ensureCrmLinks(?array $taskData, string $taskId, callable $restCall): ?array`
- `extractDealIds(array $crmLinks): array`
- `logActivityFirst(array $entry): void`

### TaskFilesService
- `getAttachedFiles(string $taskId, callable $restCall): array`
- `attachFiles(string $taskId, array $fileIds, callable $restCall): array`
- `getDiskFileInfo(string $fileId, callable $restCall): ?array`

### DealFileService
- `buildDealFileData(string $fileId, callable $restCall): ?array`
- `getDealFileField(string $dealId, string $field, callable $restCall): array`
- `buildFromDealEntry(array $entry): ?array`
- `updateDealFiles(string $dealId, string $field, array $fileDataList, callable $restCall): array`

### CommentDetailsService
- `buildDetails(array $commentData, string $eventType, ?string $requestId, ?string $taskId, ?string $commentId, ?string $sourceMethod, ?array $taskData = null): array`
- `buildFallback(string $eventType, ?string $requestId, ?string $taskId, ?string $commentId): array`
- `resolveKind($authorId): string`
- `formatDetailsRu(array $details): string`
- `writeDetailsRu(string $eventType, array $details): void`
- `findCommentItem($payload, string $commentId): ?array`
- `fetchDetails(callable $restCall, string $taskId, string $commentId): array`
- `extractChatId(array $taskData): ?string`
- `findChatMessage(array $payload, string $messageId): ?array`
- `fetchChatMessageDetails(callable $restCall, string $chatId, string $messageId): array`
- `buildDetailsFromChat(array $messageData, string $eventType, ?string $requestId, ?string $taskId, ?string $commentId, ?string $sourceMethod, ?array $taskData = null): array`
- `writeEnriched(string $eventType, string $entityId, array $taskData, array $commentData, string $sourceMethod, string $rawPath, ?string $requestId): void`
- `shouldWriteEnriched(?string $taskId): bool`

## Критерии приёмки
- [ ] `bootstrap.php` содержит только shim‑функции и минимальные утилиты.
- [ ] Вся бизнес‑логика вынесена в сервисы `outgoing-webhook/services/`.
- [ ] Полный список shim‑функций/констант с маппингом на сервисы добавлен в задачу.
- [ ] Контракты логов и JSON сохранены без изменений.
- [ ] Внешние скрипты, которые `require bootstrap.php`, работают без правок.
- [ ] Unit‑тесты для ключевых сервисов проходят.
- [ ] Документация `DOCS/OUTGOING-EVENTS/` обновлена.

## Примеры кода (опционально)
- Пример shim‑функции:
  - `outgoingWebhookReadPayload()` → `RequestService::readPayload()`
  - `outgoingWebhookLogError()` → `ErrorService::log()`

## Тестирование
1. Прогнать обработку очереди (см. `TASK-017-QA` сценарии A).
2. Сравнить `task-details.log` и `comment-details.log` до/после рефакторинга.
3. Проверить корректность `event.log` и `enriched.json` на 2–3 события.
4. Проверить, что все shim‑функции доступны и работают.
5. Отдельно проверить ветку комментариев через `im.*`.

## История правок
- 2026-01-23 22:10 (UTC+03:00, Брест): создана задача на рефакторинг bootstrap.php.
