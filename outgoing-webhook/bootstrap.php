<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Minsk');

const OUTGOING_WEBHOOK_MAX_BYTES = 2097152; // 2 MB

require_once __DIR__ . '/services/bootstrap.php';

// Глобальный контейнер для обратной совместимости
$GLOBALS['outgoingWebhookContainer'] = null;

/**
 * Получение глобального контейнера сервисов
 * 
 * @return ServiceContainer
 */
function outgoingWebhookContainer(): ServiceContainer
{
    if ($GLOBALS['outgoingWebhookContainer'] === null) {
        $GLOBALS['outgoingWebhookContainer'] = new ServiceContainer();
    }
    return $GLOBALS['outgoingWebhookContainer'];
}

/**
 * @deprecated Use ServiceContainer::get() instead
 * Сохранено для обратной совместимости
 */
function outgoingWebhookService(string $key)
{
    return outgoingWebhookContainer()->get($key);
}

// ============================================================================
// КРИТИЧЕСКИ ВАЖНЫЕ ФУНКЦИИ (оставляем без изменений)
// ============================================================================

/**
 * Получение текущего времени в формате ISO 8601
 */
function outgoingWebhookNow(): string
{
    return outgoingWebhookContainer()->get('request')->now();
}

/**
 * Отправка JSON-ответа
 */
function outgoingWebhookJsonResponse(int $statusCode, array $payload): void
{
    outgoingWebhookContainer()->get('request')->jsonResponse($statusCode, $payload);
}

/**
 * Логирование ошибки
 */
function outgoingWebhookLogError(string $message, array $context = []): void
{
    outgoingWebhookContainer()->get('errors')->log($message, $context);
}

/**
 * Получение настройки
 */
function outgoingWebhookGetSetting(string $key, ?string $default = null): ?string
{
    return outgoingWebhookContainer()->get('config')->get($key, $default);
}

/**
 * Чтение payload из запроса
 */
function outgoingWebhookReadPayload(): array
{
    return outgoingWebhookContainer()->get('request')->readPayload();
}

/**
 * Генерация ID запроса
 */
function outgoingWebhookGenerateRequestId(): string
{
    return outgoingWebhookContainer()->get('request')->generateRequestId();
}

/**
 * Извлечение информации об аутентификации
 */
function outgoingWebhookExtractAuthInfo(array $payload): array
{
    return outgoingWebhookContainer()->get('access')->extractAuthInfo($payload);
}

/**
 * Получение сервисов для синхронной обработки ActivityFirst
 * 
 * Использует ServiceContainer для создания сервисов
 * 
 * @return array Массив сервисов
 */
function outgoingWebhookGetSyncServices(): array
{
    static $services = null;
    if ($services !== null) {
        return $services;
    }

    require_once __DIR__ . '/../app/crest.php';
    require_once __DIR__ . '/../app/Services/Bitrix24Client.php';

    $container = new ServiceContainer();
    
    $services = [
        'config' => $container->get('config'),
        'filesystem' => $container->get('filesystem'),
        'request' => $container->get('request'),
        'formatter' => $container->get('formatter'),
        'errors' => $container->get('errors'),
        'rest' => $container->get('rest'),
        'taskDetails' => $container->get('taskDetails'),
        'taskFiles' => $container->get('taskFiles'),
        'dealFiles' => $container->get('dealFiles'),
        'identity' => $container->get('identity'),
        'commentDetailsService' => $container->get('commentDetails'),
    ];

    return $services;
}

/**
 * @deprecated Use outgoingWebhookProcessActivitySync() instead
 * Синхронная обработка ActivityFirst (старый формат)
 */
function outgoingWebhookProcessActivityFirstSync(
    array $commentDetails,
    string $taskId,
    string $requestId
): bool {
    return outgoingWebhookProcessActivitySync($commentDetails, $taskId, $requestId);
}

/**
 * Синхронная обработка Activity
 * 
 * Выполняется сразу после получения webhook-события (в фоне после отправки ответа)
 * 
 * @param array $commentDetails Детали комментария
 * @param string $taskId ID задачи
 * @param string $requestId ID запроса
 * @return bool Успешность обработки
 */
function outgoingWebhookProcessActivitySync(
    array $commentDetails,
    string $taskId,
    string $requestId
): bool {
    // Проверка конфигурационного флага
    $enabled = outgoingWebhookGetSetting('ACTIVITY_FIRST_SYNC_ENABLED', 'true');
    if ($enabled !== 'true' && $enabled !== '1') {
        return false;
    }

    // Поддерживаем как новый формат (activityType), так и старый (activityFirst)
    $activityType = $commentDetails['activityType'] ?? null;
    $activityFirst = $commentDetails['activityFirst'] ?? false;
    
    if ($activityType === null && !$activityFirst) {
        return false;
    }

    // Валидация данных
    $fileIds = $commentDetails['fileIds'] ?? [];
    $dealIds = $commentDetails['crmLinks'] ?? [];
    if (empty($fileIds) || empty($dealIds)) {
        outgoingWebhookLogError('ActivityFirst sync: missing required data', [
            'requestId' => $requestId,
            'taskId' => $taskId,
            'fileIds' => $fileIds,
            'dealIds' => $dealIds,
        ]);
        return false;
    }

    // Rate limiting
    $rateLimit = (int) outgoingWebhookGetSetting('ACTIVITY_FIRST_SYNC_RATE_LIMIT', '5');
    $stateDir = __DIR__ . '/state';
    outgoingWebhookSafeMkdir($stateDir);
    $lockFile = $stateDir . '/activity-first-sync.lock';
    $lockHandle = fopen($lockFile, 'c+');
    if (!$lockHandle) {
        outgoingWebhookLogError('ActivityFirst sync: cannot create lock file', [
            'requestId' => $requestId,
            'taskId' => $taskId,
        ]);
        return false;
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        outgoingWebhookLogError('ActivityFirst sync: rate limit exceeded', [
            'requestId' => $requestId,
            'taskId' => $taskId,
        ]);
        return false;
    }

    $startTime = microtime(true);

    try {
        $services = outgoingWebhookGetSyncServices();
        $restCall = fn(string $method, array $params = []) => $services['rest']->call($method, $params);
        
        $result = $services['commentDetailsService']->processActivityFirst(
            $commentDetails,
            $taskId,
            $restCall
        );

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Маркировать как обработанное
        $services['taskDetails']->markActivityFirstProcessed($requestId, $taskId);

        // Логирование результата
        $logEntry = [
            'loggedAt' => $services['request']->now(),
            'requestId' => $requestId,
            'taskId' => $taskId,
            'sync' => true,
            'dealIds' => $result['dealIds'],
            'fileIds' => $result['fileIds'],
            'taskAttach' => $result['taskAttach'],
            'dealUpdates' => $result['dealUpdates'],
        ];
        
        // Добавляем activityType и dealField если они есть (новый формат)
        if (isset($result['activityType'])) {
            $logEntry['activityType'] = $result['activityType'];
        }
        if (isset($result['dealField'])) {
            $logEntry['dealField'] = $result['dealField'];
        }
        
        $services['taskDetails']->logActivityFirst($logEntry);

        // Логирование метрик
        $metricsPath = __DIR__ . '/logs/activity-first-metrics.log';
        $metrics = [
            'loggedAt' => $services['request']->now(),
            'requestId' => $requestId,
            'taskId' => $taskId,
            'sync' => true,
            'durationMs' => $durationMs,
            'success' => true,
            'filesCount' => count($result['fileIds']),
            'dealsCount' => count($result['dealIds']),
            'rateLimitHit' => false,
        ];
        
        // Добавляем activityType если есть (новый формат)
        if (isset($result['activityType'])) {
            $metrics['activityType'] = $result['activityType'];
        }
        
        $services['filesystem']->appendLine($metricsPath, json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        return true;
    } catch (Throwable $e) {
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        
        outgoingWebhookLogError('ActivityFirst sync processing failed', [
            'requestId' => $requestId,
            'taskId' => $taskId,
            'message' => $e->getMessage(),
            'durationMs' => $durationMs,
        ]);

        // Логирование метрик ошибки
        $services = outgoingWebhookGetSyncServices();
        $metricsPath = __DIR__ . '/logs/activity-first-metrics.log';
        $metrics = [
            'loggedAt' => $services['request']->now(),
            'requestId' => $requestId,
            'taskId' => $taskId,
            'sync' => true,
            'durationMs' => $durationMs,
            'success' => false,
            'error' => $e->getMessage(),
        ];
        
        // Добавляем activityType если есть (новый формат)
        $activityType = $commentDetails['activityType'] ?? null;
        if ($activityType !== null) {
            $metrics['activityType'] = $activityType;
        }
        
        $services['filesystem']->appendLine($metricsPath, json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        return false;
    }
}

// ============================================================================
// ФУНКЦИИ ДЛЯ ОБРАТНОЙ СОВМЕСТИМОСТИ (@deprecated)
// ============================================================================

/**
 * @deprecated Use ServiceContainer::get('config')->getEnv($key, $default) instead
 */
function outgoingWebhookGetEnv(string $key, ?string $default = null): ?string
{
    return outgoingWebhookContainer()->get('config')->getEnv($key, $default);
}

/**
 * @deprecated Use ServiceContainer::get('config')->getConfig() instead
 */
function outgoingWebhookGetConfig(): array
{
    return outgoingWebhookContainer()->get('config')->getConfig();
}

/**
 * @deprecated Use ServiceContainer::get('filesystem')->ensureDir($path) instead
 */
function outgoingWebhookSafeMkdir(string $path): void
{
    outgoingWebhookContainer()->get('filesystem')->ensureDir($path);
}

/**
 * @deprecated Use ServiceContainer::get('filesystem')->writeJson($path, $data) instead
 */
function outgoingWebhookWriteJson(string $path, array $data): bool
{
    return outgoingWebhookContainer()->get('filesystem')->writeJson($path, $data);
}

/**
 * @deprecated Use ServiceContainer::get('filesystem')->appendLine($path, $line) instead
 */
function outgoingWebhookAppendLine(string $path, string $line): bool
{
    return outgoingWebhookContainer()->get('filesystem')->appendLine($path, $line);
}

/**
 * @deprecated Use ServiceContainer::get('formatter')->maskValue($value) instead
 */
function outgoingWebhookMaskValue(string $value): string
{
    return outgoingWebhookContainer()->get('formatter')->maskValue($value);
}

/**
 * @deprecated Use ServiceContainer::get('formatter')->maskPayload($payload) instead
 */
function outgoingWebhookMaskPayload(array $payload): array
{
    return outgoingWebhookContainer()->get('formatter')->maskPayload($payload);
}

/**
 * @deprecated Use ServiceContainer::get('formatter')->normalize($value) instead
 */
function outgoingWebhookNormalizeLogValue($value): string
{
    return outgoingWebhookContainer()->get('formatter')->normalize($value);
}

/**
 * @deprecated Use ServiceContainer::get('access')->getAllowedIps() instead
 */
function outgoingWebhookGetAllowedIps(): array
{
    return outgoingWebhookContainer()->get('access')->getAllowedIps();
}

/**
 * @deprecated Use ServiceContainer::get('request')->normalizeEventType($event) instead
 */
function outgoingWebhookNormalizeEventType(?string $event): string
{
    return outgoingWebhookContainer()->get('request')->normalizeEventType($event);
}

/**
 * @deprecated Use ServiceContainer::get('identity')->extractEntityId($payload) instead
 */
function outgoingWebhookExtractEntityId(array $payload): ?string
{
    return outgoingWebhookContainer()->get('identity')->extractEntityId($payload);
}

/**
 * @deprecated Use ServiceContainer::get('identity')->extractCommentId($payload) instead
 */
function outgoingWebhookExtractCommentId(array $payload): ?string
{
    return outgoingWebhookContainer()->get('identity')->extractCommentId($payload);
}

/**
 * @deprecated Use ServiceContainer::get('identity')->normalizeEntityId($entityId) instead
 */
function outgoingWebhookNormalizeEntityId(?string $entityId): ?string
{
    return outgoingWebhookContainer()->get('identity')->normalizeEntityId($entityId);
}

/**
 * @deprecated Use ServiceContainer::get('identity')->extractTaskId($payload) instead
 */
function outgoingWebhookExtractTaskId(array $payload): ?string
{
    return outgoingWebhookContainer()->get('identity')->extractTaskId($payload);
}

/**
 * @deprecated Use ServiceContainer::get('identity')->extractMessageId($payload) instead
 */
function outgoingWebhookExtractMessageId(array $payload): ?string
{
    return outgoingWebhookContainer()->get('identity')->extractMessageId($payload);
}

/**
 * @deprecated Use ServiceContainer::get('access')->extractAuthToken($payload) instead
 */
function outgoingWebhookExtractAuthToken(array $payload): string
{
    return outgoingWebhookContainer()->get('access')->extractAuthToken($payload);
}

/**
 * @deprecated Use ServiceContainer::get('request')->getFirstValue($data, $keys) instead
 */
function outgoingWebhookGetFirstValue(array $data, array $keys)
{
    return outgoingWebhookContainer()->get('request')->getFirstValue($data, $keys);
}

/**
 * @deprecated Use ServiceContainer::get('identity')->resolveEntityType($eventType) instead
 */
function outgoingWebhookResolveEntityType(string $eventType): string
{
    return outgoingWebhookContainer()->get('identity')->resolveEntityType($eventType);
}

// Остальные функции-обертки для обратной совместимости (помечены как @deprecated)
// Используются в других файлах проекта, поэтому оставлены для совместимости

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->extractTaskData($enriched) instead
 */
function outgoingWebhookExtractTaskData(array $enriched): ?array
{
    return outgoingWebhookContainer()->get('taskDetails')->extractTaskData($enriched);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->buildDetails(...) instead
 */
function outgoingWebhookBuildTaskDetails(
    array $taskData,
    string $eventType,
    ?string $requestId,
    ?string $entityId
): array {
    return outgoingWebhookContainer()->get('taskDetails')->buildDetails($taskData, $eventType, $requestId, $entityId);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->formatDetailsRu($details) instead
 */
function outgoingWebhookFormatTaskDetailsRu(array $details): string
{
    return outgoingWebhookContainer()->get('taskDetails')->formatDetailsRu($details);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->writeDetailsRu($eventType, $details) instead
 */
function outgoingWebhookWriteTaskDetailsRu(string $eventType, array $details): void
{
    outgoingWebhookContainer()->get('taskDetails')->writeDetailsRu($eventType, $details);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->extractMeta($taskData) instead
 */
function outgoingWebhookExtractTaskMeta(?array $taskData): array
{
    return outgoingWebhookContainer()->get('taskDetails')->extractMeta($taskData);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->loadActivityFirstConditions() instead
 */
function outgoingWebhookLoadActivityFirstConditions(): array
{
    return outgoingWebhookContainer()->get('taskDetails')->loadActivityFirstConditions();
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->messageHasKeyword($message, $keywords) instead
 */
function outgoingWebhookMessageHasKeyword(string $message, array $keywords): bool
{
    return outgoingWebhookContainer()->get('taskDetails')->messageHasKeyword($message, $keywords);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->hasDealLink($crmLinks, $dealPrefix) instead
 */
function outgoingWebhookHasDealLink(array $crmLinks, string $dealPrefix): bool
{
    return outgoingWebhookContainer()->get('taskDetails')->hasDealLink($crmLinks, $dealPrefix);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->evaluateActivityFirst($details) instead
 */
function outgoingWebhookEvaluateActivityFirst(array $details): bool
{
    return outgoingWebhookContainer()->get('taskDetails')->evaluateActivityFirst($details);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->loadCrmLinks($taskId, $restCall) instead
 */
function outgoingWebhookLoadTaskCrmLinks(string $taskId, callable $restCall): array
{
    return outgoingWebhookContainer()->get('taskDetails')->loadCrmLinks($taskId, $restCall);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->ensureCrmLinks($taskData, $taskId, $restCall) instead
 */
function outgoingWebhookEnsureTaskCrmLinks(?array $taskData, string $taskId, callable $restCall): ?array
{
    return outgoingWebhookContainer()->get('taskDetails')->ensureCrmLinks($taskData, $taskId, $restCall);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->extractDealIds($crmLinks) instead
 */
function outgoingWebhookExtractDealIds(array $crmLinks): array
{
    return outgoingWebhookContainer()->get('taskDetails')->extractDealIds($crmLinks);
}

/**
 * @deprecated Use ServiceContainer::get('taskFiles')->getAttachedFiles($taskId, $restCall) instead
 */
function outgoingWebhookGetTaskAttachedFiles(string $taskId, callable $restCall): array
{
    return outgoingWebhookContainer()->get('taskFiles')->getAttachedFiles($taskId, $restCall);
}

/**
 * @deprecated Use ServiceContainer::get('taskFiles')->attachFiles($taskId, $fileIds, $restCall) instead
 */
function outgoingWebhookAttachFilesToTask(string $taskId, array $fileIds, callable $restCall): array
{
    return outgoingWebhookContainer()->get('taskFiles')->attachFiles($taskId, $fileIds, $restCall);
}

/**
 * @deprecated Use ServiceContainer::get('taskFiles')->getDiskFileInfo($fileId, $restCall) instead
 */
function outgoingWebhookGetDiskFileInfo(string $fileId, callable $restCall): ?array
{
    return outgoingWebhookContainer()->get('taskFiles')->getDiskFileInfo($fileId, $restCall);
}

/**
 * @deprecated Use ServiceContainer::get('filesystem')->downloadBase64($url) instead
 */
function outgoingWebhookDownloadBase64FromUrl(string $url): ?string
{
    return outgoingWebhookContainer()->get('filesystem')->downloadBase64($url);
}

/**
 * @deprecated Use ServiceContainer::get('config')->getClientEndpoint() instead
 */
function outgoingWebhookGetClientEndpoint(): ?string
{
    return outgoingWebhookContainer()->get('config')->getClientEndpoint();
}

/**
 * @deprecated Use ServiceContainer::get('request')->resolveAbsoluteUrl($url) instead
 */
function outgoingWebhookResolveAbsoluteUrl(string $url): string
{
    return outgoingWebhookContainer()->get('request')->resolveAbsoluteUrl($url);
}

/**
 * @deprecated Use ServiceContainer::get('dealFiles')->buildDealFileData($fileId, $restCall) instead
 */
function outgoingWebhookBuildDealFileData(string $fileId, callable $restCall): ?array
{
    return outgoingWebhookContainer()->get('dealFiles')->buildDealFileData($fileId, $restCall);
}

/**
 * @deprecated Use ServiceContainer::get('dealFiles')->getDealFileField($dealId, $field, $restCall) instead
 */
function outgoingWebhookGetDealFileField(string $dealId, string $field, callable $restCall): array
{
    return outgoingWebhookContainer()->get('dealFiles')->getDealFileField($dealId, $field, $restCall);
}

/**
 * @deprecated Use ServiceContainer::get('dealFiles')->buildFromDealEntry($entry, $restCall) instead
 */
function outgoingWebhookBuildDealFileDataFromDealEntry(array $entry, callable $restCall): ?array
{
    return outgoingWebhookContainer()->get('dealFiles')->buildFromDealEntry($entry, $restCall);
}

/**
 * @deprecated Use ServiceContainer::get('dealFiles')->updateDealFiles($dealId, $field, $fileDataList, $restCall) instead
 */
function outgoingWebhookUpdateDealFiles(string $dealId, string $field, array $fileDataList, callable $restCall): array
{
    return outgoingWebhookContainer()->get('dealFiles')->updateDealFiles($dealId, $field, $fileDataList, $restCall);
}

/**
 * @deprecated Use ServiceContainer::get('taskDetails')->logActivityFirst($entry) instead
 */
function outgoingWebhookLogActivityFirst(array $entry): void
{
    outgoingWebhookContainer()->get('taskDetails')->logActivityFirst($entry);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->buildDetails(...) instead
 */
function outgoingWebhookBuildCommentDetails(
    array $commentData,
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId,
    ?string $sourceMethod,
    ?array $taskData = null
): array {
    return outgoingWebhookContainer()->get('commentDetails')->buildDetails(
        $commentData,
        $eventType,
        $requestId,
        $taskId,
        $commentId,
        $sourceMethod,
        $taskData
    );
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->buildFallback(...) instead
 */
function outgoingWebhookBuildCommentFallback(
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId
): array {
    return outgoingWebhookContainer()->get('commentDetails')->buildFallback($eventType, $requestId, $taskId, $commentId);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->resolveKind($authorId) instead
 */
function outgoingWebhookResolveCommentKind($authorId): string
{
    return outgoingWebhookContainer()->get('commentDetails')->resolveKind($authorId);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->formatDetailsRu($details) instead
 */
function outgoingWebhookFormatCommentDetailsRu(array $details): string
{
    return outgoingWebhookContainer()->get('commentDetails')->formatDetailsRu($details);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->writeDetailsRu($eventType, $details) instead
 */
function outgoingWebhookWriteCommentDetailsRu(string $eventType, array $details): void
{
    outgoingWebhookContainer()->get('commentDetails')->writeDetailsRu($eventType, $details);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->findCommentItem($payload, $commentId) instead
 */
function outgoingWebhookFindCommentItem($payload, string $commentId): ?array
{
    return outgoingWebhookContainer()->get('commentDetails')->findCommentItem($payload, $commentId);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->fetchDetails($restCall, $taskId, $commentId) instead
 */
function outgoingWebhookFetchCommentDetails(callable $restCall, string $taskId, string $commentId): array
{
    return outgoingWebhookContainer()->get('commentDetails')->fetchDetails($restCall, $taskId, $commentId);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->extractChatId($taskData) instead
 */
function outgoingWebhookExtractChatId(array $taskData): ?string
{
    return outgoingWebhookContainer()->get('commentDetails')->extractChatId($taskData);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->findChatMessage($payload, $messageId) instead
 */
function outgoingWebhookFindChatMessage(array $payload, string $messageId): ?array
{
    return outgoingWebhookContainer()->get('commentDetails')->findChatMessage($payload, $messageId);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->fetchChatMessageDetails($restCall, $chatId, $messageId) instead
 */
function outgoingWebhookFetchChatMessageDetails(callable $restCall, string $chatId, string $messageId): array
{
    return outgoingWebhookContainer()->get('commentDetails')->fetchChatMessageDetails($restCall, $chatId, $messageId);
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->buildDetailsFromChat(...) instead
 */
function outgoingWebhookBuildCommentDetailsFromChat(
    array $messageData,
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId,
    ?string $sourceMethod,
    ?array $taskData = null
): array {
    return outgoingWebhookContainer()->get('commentDetails')->buildDetailsFromChat(
        $messageData,
        $eventType,
        $requestId,
        $taskId,
        $commentId,
        $sourceMethod,
        $taskData
    );
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->writeEnriched(...) instead
 */
function outgoingWebhookWriteCommentEnriched(
    string $eventType,
    string $entityId,
    array $taskData,
    array $commentData,
    string $sourceMethod,
    string $rawPath,
    ?string $requestId
): void {
    outgoingWebhookContainer()->get('commentDetails')->writeEnriched(
        $eventType,
        $entityId,
        $taskData,
        $commentData,
        $sourceMethod,
        $rawPath,
        $requestId
    );
}

/**
 * @deprecated Use ServiceContainer::get('commentDetails')->shouldWriteEnriched($taskId) instead
 */
function outgoingWebhookShouldWriteCommentEnriched(?string $taskId): bool
{
    return outgoingWebhookContainer()->get('commentDetails')->shouldWriteEnriched($taskId);
}
