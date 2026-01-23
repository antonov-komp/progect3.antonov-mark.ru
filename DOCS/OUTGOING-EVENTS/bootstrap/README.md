 # outgoing-webhook/bootstrap.php — описание по разделам
 
 Дата создания: 2026-01-23 18:48 (UTC+03:00, Брест)
 
## Назначение файла
`outgoing-webhook/bootstrap.php` — точка входа с shim‑функциями. Бизнес‑логика
вынесена в сервисы, а функции оставлены для backward‑compatibility.
 
## Раздел 1. Базовые настройки и утилиты
 - Таймзона: `Europe/Minsk`.
 - Лимит входящего payload: `OUTGOING_WEBHOOK_MAX_BYTES` (2 MB).
- `outgoingWebhookNow()` — единый формат времени (`RequestService::now()`).
- `outgoingWebhookGetEnv()` / `outgoingWebhookGetConfig()` / `outgoingWebhookGetSetting()` —
  shim на `ConfigService`.
 
## Раздел 2. Файловая система и логирование
- `outgoingWebhookSafeMkdir()` — `FilesystemService::ensureDir()`.
- `outgoingWebhookWriteJson()` — `FilesystemService::writeJson()`.
- `outgoingWebhookAppendLine()` — `FilesystemService::appendLine()`.
- `outgoingWebhookLogError()` — `ErrorService::log()`.
- `outgoingWebhookNormalizeLogValue()` — `LogValueFormatter::normalize()`.
 
## Раздел 3. Маскирование чувствительных данных
- `outgoingWebhookMaskValue()` — `LogValueFormatter::maskValue()` (маска `****<last4>`).
- `outgoingWebhookMaskPayload()` — `LogValueFormatter::maskPayload()`.
 
## Раздел 4. Чтение payload и нормализация события
- `outgoingWebhookReadPayload()` — `RequestService::readPayload()`.
- `outgoingWebhookNormalizeEventType()` — `RequestService::normalizeEventType()`.
 
## Раздел 5. Извлечение идентификаторов и auth
- `outgoingWebhookExtractEntityId()` — `EntityIdentityService::extractEntityId()`.
- `outgoingWebhookNormalizeEntityId()` — `EntityIdentityService::normalizeEntityId()`.
- `outgoingWebhookExtractTaskId()` / `outgoingWebhookExtractMessageId()` —
  `EntityIdentityService`.
- `outgoingWebhookExtractAuthToken()` / `outgoingWebhookExtractAuthInfo()` —
  `AccessService`.
 
## Раздел 6. Универсальные хелперы
- `outgoingWebhookGetFirstValue()` — `RequestService::getFirstValue()`.
- `outgoingWebhookExtractTaskData()` — `TaskDetailsService::extractTaskData()`.
 
## Раздел 7. Детали задачи (task-details.log)
- `outgoingWebhookBuildTaskDetails()` — `TaskDetailsService::buildDetails()`.
- `outgoingWebhookFormatTaskDetailsRu()` — `TaskDetailsService::formatDetailsRu()`.
- `outgoingWebhookWriteTaskDetailsRu()` — `TaskDetailsService::writeDetailsRu()`.
 
## Раздел 8. Метаданные задачи и CRM‑связи
- `outgoingWebhookExtractTaskMeta()` — `TaskDetailsService::extractMeta()`.
- `outgoingWebhookEnsureTaskCrmLinks()` и `outgoingWebhookLoadTaskCrmLinks()` —
  `TaskDetailsService`.
- `outgoingWebhookExtractDealIds()` — `TaskDetailsService::extractDealIds()`.
 
## Раздел 9. Activity First
- `outgoingWebhookLoadActivityFirstConditions()` — `TaskDetailsService::loadActivityFirstConditions()`.
- `outgoingWebhookEvaluateActivityFirst()` — `TaskDetailsService::evaluateActivityFirst()`.
- `outgoingWebhookHasDealLink()` / `outgoingWebhookMessageHasKeyword()` —
  `TaskDetailsService`.
 
## Раздел 10. Детали комментариев (comment-details.log)
- `outgoingWebhookFetchCommentDetails()` — `CommentDetailsService::fetchDetails()`.
- `outgoingWebhookFetchChatMessageDetails()` — `CommentDetailsService::fetchChatMessageDetails()`.
- `outgoingWebhookBuildCommentDetails()` / `outgoingWebhookBuildCommentDetailsFromChat()` —
  `CommentDetailsService`.
- `outgoingWebhookFormatCommentDetailsRu()` / `outgoingWebhookWriteCommentDetailsRu()` —
  `CommentDetailsService`.
 
## Раздел 11. Сопоставление типа сущности
- `outgoingWebhookResolveEntityType()` — `EntityIdentityService::resolveEntityType()`.
 
## Раздел 12. Ответы эндпоинта
- `outgoingWebhookJsonResponse()` — `RequestService::jsonResponse()`.
 
## Где файл используется
- `outgoing-webhook/index.php` — приём события, запись логов, быстрые детали задач/комментариев.
- `outgoing-webhook/tools/process-queue.php` — тонкая обёртка над сервисами очереди.
- `outgoing-webhook/tools/process-queue-cli.php` — CLI‑запуск (cron) с теми же сервисами.

## Связанные сервисы очереди
- `outgoing-webhook/services/Queue/QueueRunner.php` — orchestration очереди.
- `outgoing-webhook/services/Enrichment/EnrichmentService.php` — REST‑обогащение.
- `outgoing-webhook/services/Task/*` — детали задач/комментариев.
- `outgoing-webhook/services/Core/*`, `Http/*`, `Security/*`, `Identity/*`, `Crm/*` — доменные сервисы.
 
## Изменения
- 2026-01-23 18:48 (UTC+03:00, Брест): создано описание файла по разделам.
- 2026-01-23 20:10 (UTC+03:00, Брест): добавлена связка с сервисами очереди.
- 2026-01-23 22:45 (UTC+03:00, Брест): обновлено под shim‑модель и сервисы.
