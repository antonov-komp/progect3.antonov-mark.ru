 # outgoing-webhook/bootstrap.php — описание по разделам
 
 Дата создания: 2026-01-23 18:48 (UTC+03:00, Брест)
 
 ## Назначение файла
 `outgoing-webhook/bootstrap.php` — общий набор функций для модуля исходящих событий:
 работа с конфигом, безопасная запись логов, чтение payload, извлечение идентификаторов,
 формирование «деталей» задач/комментариев и проверка Activity First.
 
 ## Раздел 1. Базовые настройки и утилиты
 - Таймзона: `Europe/Minsk`.
 - Лимит входящего payload: `OUTGOING_WEBHOOK_MAX_BYTES` (2 MB).
 - `outgoingWebhookNow()` — единый формат времени (`date('c')`).
 - `outgoingWebhookGetEnv()` / `outgoingWebhookGetConfig()` / `outgoingWebhookGetSetting()` —
   источник настроек из env или `config.local.php`.
 
 ## Раздел 2. Файловая система и логирование
 - `outgoingWebhookSafeMkdir()` — безопасное создание каталогов.
 - `outgoingWebhookWriteJson()` — запись JSON (pretty + unicode).
 - `outgoingWebhookAppendLine()` — построчная запись.
 - `outgoingWebhookLogError()` — лог ошибок в `logs/errors/` + `error_log`.
 - `outgoingWebhookNormalizeLogValue()` — нормализация текста для логов.
 
 ## Раздел 3. Маскирование чувствительных данных
 - `outgoingWebhookMaskValue()` — маска `****<last4>`.
 - `outgoingWebhookMaskPayload()` — рекурсивное маскирование поля `token`.
 
 ## Раздел 4. Чтение payload и нормализация события
 - `outgoingWebhookReadPayload()` — поддержка JSON, form-data и raw body.
 - `outgoingWebhookNormalizeEventType()` — верхний регистр, удаление лишних символов.
 
 ## Раздел 5. Извлечение идентификаторов и auth
 - `outgoingWebhookExtractEntityId()` — поиск ID в `data.*` (включая `TASK_ID`).
 - `outgoingWebhookNormalizeEntityId()` — нормализация ID.
 - `outgoingWebhookExtractTaskId()` / `outgoingWebhookExtractMessageId()` —
   отдельные пути для задач и сообщений.
 - `outgoingWebhookExtractAuthToken()` / `outgoingWebhookExtractAuthInfo()` —
   поддержка `token` и `auth.application_token/app_token`.
 
 ## Раздел 6. Универсальные хелперы
 - `outgoingWebhookGetFirstValue()` — поиск значения по набору ключей/путей.
 - `outgoingWebhookExtractTaskData()` — извлечение массива задачи из enriched.
 
 ## Раздел 7. Детали задачи (task-details.log)
 - `outgoingWebhookBuildTaskDetails()` — сборка ключевых полей задачи.
 - `outgoingWebhookFormatTaskDetailsRu()` — формат строки лога.
 - `outgoingWebhookWriteTaskDetailsRu()` — запись в `logs/<EVENT>/task-details.log`.
 
 ## Раздел 8. Метаданные задачи и CRM‑связи
 - `outgoingWebhookExtractTaskMeta()` — проект/название/CRM‑связи.
 - `outgoingWebhookEnsureTaskCrmLinks()` и `outgoingWebhookLoadTaskCrmLinks()` —
   дополучение `UF_CRM_TASK`.
 - `outgoingWebhookExtractDealIds()` — извлечение ID сделок из CRM‑ссылок.
 
 ## Раздел 9. Activity First
 - `outgoingWebhookLoadActivityFirstConditions()` — загрузка условий из
   `outgoing-webhook/activity/first/conditions.php`.
 - `outgoingWebhookEvaluateActivityFirst()` — проверка правил (проект, CRM‑связь,
   ключевое слово, файлы).
 - `outgoingWebhookHasDealLink()` / `outgoingWebhookMessageHasKeyword()` —
   вспомогательные проверки.
 
 ## Раздел 10. Детали комментариев (comment-details.log)
 - `outgoingWebhookFetchCommentDetails()` — подбор REST‑метода для комментария задачи.
 - `outgoingWebhookFetchChatMessageDetails()` — fallback через чат `im.dialog.messages.get`.
 - `outgoingWebhookBuildCommentDetails()` / `outgoingWebhookBuildCommentDetailsFromChat()` —
   сборка деталей комментария.
 - `outgoingWebhookFormatCommentDetailsRu()` / `outgoingWebhookWriteCommentDetailsRu()` —
   формат и запись в `logs/<EVENT>/comment-details.log`.
 
 ## Раздел 11. Сопоставление типа сущности
 - `outgoingWebhookResolveEntityType()` — mapping `eventType → entityType`.
 
 ## Раздел 12. Ответы эндпоинта
 - `outgoingWebhookJsonResponse()` — единая JSON‑обёртка ответов.
 
## Где файл используется
- `outgoing-webhook/index.php` — приём события, запись логов, быстрые детали задач/комментариев.
- `outgoing-webhook/tools/process-queue.php` — тонкая обёртка над сервисами очереди.
- `outgoing-webhook/tools/process-queue-cli.php` — CLI‑запуск (cron) с теми же сервисами.

## Связанные сервисы очереди
- `outgoing-webhook/services/Queue/QueueRunner.php` — orchestration очереди.
- `outgoing-webhook/services/Enrichment/EnrichmentService.php` — REST‑обогащение.
- `outgoing-webhook/services/Task/*` — детали задач/комментариев без изменения формата.
 
## Изменения
- 2026-01-23 18:48 (UTC+03:00, Брест): создано описание файла по разделам.
- 2026-01-23 20:10 (UTC+03:00, Брест): добавлена связка с сервисами очереди.
