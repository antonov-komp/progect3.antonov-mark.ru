<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Minsk');

const OUTGOING_WEBHOOK_MAX_BYTES = 2097152; // 2 MB

require_once __DIR__ . '/services/bootstrap.php';

function outgoingWebhookService(string $key)
{
    static $services = [];
    if (isset($services[$key])) {
        return $services[$key];
    }

    switch ($key) {
        case 'config':
            return $services[$key] = new ConfigService();
        case 'filesystem':
            return $services[$key] = new FilesystemService();
        case 'request':
            return $services[$key] = new RequestService(outgoingWebhookService('config'));
        case 'access':
            return $services[$key] = new AccessService(outgoingWebhookService('config'));
        case 'formatter':
            return $services[$key] = new LogValueFormatter();
        case 'errors':
            return $services[$key] = new ErrorService(
                outgoingWebhookService('filesystem'),
                outgoingWebhookService('request')
            );
        case 'identity':
            return $services[$key] = new EntityIdentityService(outgoingWebhookService('request'));
        case 'taskDetails':
            return $services[$key] = new TaskDetailsService(
                outgoingWebhookService('filesystem'),
                outgoingWebhookService('request'),
                outgoingWebhookService('formatter')
            );
        case 'taskFiles':
            return $services[$key] = new TaskFilesService();
        case 'dealFiles':
            return $services[$key] = new DealFileService(
                outgoingWebhookService('filesystem'),
                outgoingWebhookService('request'),
                outgoingWebhookService('taskFiles')
            );
        case 'commentDetails':
            return $services[$key] = new CommentDetailsService(
                null,
                outgoingWebhookService('errors'),
                outgoingWebhookService('taskDetails'),
                outgoingWebhookService('taskFiles'),
                outgoingWebhookService('dealFiles'),
                outgoingWebhookService('identity'),
                outgoingWebhookService('request'),
                outgoingWebhookService('formatter'),
                outgoingWebhookService('filesystem'),
                outgoingWebhookService('config')
            );
        default:
            throw new InvalidArgumentException('Unknown service: ' . $key);
    }
}

function outgoingWebhookNow(): string
{
    return outgoingWebhookService('request')->now();
}

function outgoingWebhookGetEnv(string $key, ?string $default = null): ?string
{
    return outgoingWebhookService('config')->getEnv($key, $default);
}

function outgoingWebhookGetConfig(): array
{
    return outgoingWebhookService('config')->getConfig();
}

function outgoingWebhookGetSetting(string $key, ?string $default = null): ?string
{
    return outgoingWebhookService('config')->get($key, $default);
}

function outgoingWebhookSafeMkdir(string $path): void
{
    outgoingWebhookService('filesystem')->ensureDir($path);
}

function outgoingWebhookWriteJson(string $path, array $data): bool
{
    return outgoingWebhookService('filesystem')->writeJson($path, $data);
}

function outgoingWebhookAppendLine(string $path, string $line): bool
{
    return outgoingWebhookService('filesystem')->appendLine($path, $line);
}

function outgoingWebhookLogError(string $message, array $context = []): void
{
    outgoingWebhookService('errors')->log($message, $context);
}

function outgoingWebhookMaskValue(string $value): string
{
    return outgoingWebhookService('formatter')->maskValue($value);
}

function outgoingWebhookMaskPayload(array $payload): array
{
    return outgoingWebhookService('formatter')->maskPayload($payload);
}

function outgoingWebhookNormalizeLogValue($value): string
{
    return outgoingWebhookService('formatter')->normalize($value);
}

function outgoingWebhookGenerateRequestId(): string
{
    return outgoingWebhookService('request')->generateRequestId();
}

function outgoingWebhookGetAllowedIps(): array
{
    return outgoingWebhookService('access')->getAllowedIps();
}

function outgoingWebhookReadPayload(): array
{
    return outgoingWebhookService('request')->readPayload();
}

function outgoingWebhookNormalizeEventType(?string $event): string
{
    return outgoingWebhookService('request')->normalizeEventType($event);
}

function outgoingWebhookExtractEntityId(array $payload): ?string
{
    return outgoingWebhookService('identity')->extractEntityId($payload);
}

function outgoingWebhookExtractCommentId(array $payload): ?string
{
    return outgoingWebhookService('identity')->extractCommentId($payload);
}

function outgoingWebhookNormalizeEntityId(?string $entityId): ?string
{
    return outgoingWebhookService('identity')->normalizeEntityId($entityId);
}

function outgoingWebhookExtractTaskId(array $payload): ?string
{
    return outgoingWebhookService('identity')->extractTaskId($payload);
}

function outgoingWebhookExtractMessageId(array $payload): ?string
{
    return outgoingWebhookService('identity')->extractMessageId($payload);
}

function outgoingWebhookExtractAuthToken(array $payload): string
{
    return outgoingWebhookService('access')->extractAuthToken($payload);
}

function outgoingWebhookExtractAuthInfo(array $payload): array
{
    return outgoingWebhookService('access')->extractAuthInfo($payload);
}

function outgoingWebhookGetFirstValue(array $data, array $keys)
{
    return outgoingWebhookService('request')->getFirstValue($data, $keys);
}

function outgoingWebhookExtractTaskData(array $enriched): ?array
{
    return outgoingWebhookService('taskDetails')->extractTaskData($enriched);
}

function outgoingWebhookBuildTaskDetails(
    array $taskData,
    string $eventType,
    ?string $requestId,
    ?string $entityId
): array {
    return outgoingWebhookService('taskDetails')->buildDetails($taskData, $eventType, $requestId, $entityId);
}

function outgoingWebhookFormatTaskDetailsRu(array $details): string
{
    return outgoingWebhookService('taskDetails')->formatDetailsRu($details);
}

function outgoingWebhookWriteTaskDetailsRu(string $eventType, array $details): void
{
    outgoingWebhookService('taskDetails')->writeDetailsRu($eventType, $details);
}

function outgoingWebhookExtractTaskMeta(?array $taskData): array
{
    return outgoingWebhookService('taskDetails')->extractMeta($taskData);
}

function outgoingWebhookLoadActivityFirstConditions(): array
{
    return outgoingWebhookService('taskDetails')->loadActivityFirstConditions();
}

function outgoingWebhookMessageHasKeyword(string $message, array $keywords): bool
{
    return outgoingWebhookService('taskDetails')->messageHasKeyword($message, $keywords);
}

function outgoingWebhookHasDealLink(array $crmLinks, string $dealPrefix): bool
{
    return outgoingWebhookService('taskDetails')->hasDealLink($crmLinks, $dealPrefix);
}

function outgoingWebhookEvaluateActivityFirst(array $details): bool
{
    return outgoingWebhookService('taskDetails')->evaluateActivityFirst($details);
}

function outgoingWebhookLoadTaskCrmLinks(string $taskId, callable $restCall): array
{
    return outgoingWebhookService('taskDetails')->loadCrmLinks($taskId, $restCall);
}

function outgoingWebhookEnsureTaskCrmLinks(?array $taskData, string $taskId, callable $restCall): ?array
{
    return outgoingWebhookService('taskDetails')->ensureCrmLinks($taskData, $taskId, $restCall);
}

function outgoingWebhookExtractDealIds(array $crmLinks): array
{
    return outgoingWebhookService('taskDetails')->extractDealIds($crmLinks);
}

function outgoingWebhookGetTaskAttachedFiles(string $taskId, callable $restCall): array
{
    return outgoingWebhookService('taskFiles')->getAttachedFiles($taskId, $restCall);
}

function outgoingWebhookAttachFilesToTask(string $taskId, array $fileIds, callable $restCall): array
{
    return outgoingWebhookService('taskFiles')->attachFiles($taskId, $fileIds, $restCall);
}

function outgoingWebhookGetDiskFileInfo(string $fileId, callable $restCall): ?array
{
    return outgoingWebhookService('taskFiles')->getDiskFileInfo($fileId, $restCall);
}

function outgoingWebhookDownloadBase64FromUrl(string $url): ?string
{
    return outgoingWebhookService('filesystem')->downloadBase64($url);
}

function outgoingWebhookGetClientEndpoint(): ?string
{
    return outgoingWebhookService('config')->getClientEndpoint();
}

function outgoingWebhookResolveAbsoluteUrl(string $url): string
{
    return outgoingWebhookService('request')->resolveAbsoluteUrl($url);
}

function outgoingWebhookBuildDealFileData(string $fileId, callable $restCall): ?array
{
    return outgoingWebhookService('dealFiles')->buildDealFileData($fileId, $restCall);
}

function outgoingWebhookGetDealFileField(string $dealId, string $field, callable $restCall): array
{
    return outgoingWebhookService('dealFiles')->getDealFileField($dealId, $field, $restCall);
}

function outgoingWebhookBuildDealFileDataFromDealEntry(array $entry): ?array
{
    return outgoingWebhookService('dealFiles')->buildFromDealEntry($entry);
}

function outgoingWebhookUpdateDealFiles(string $dealId, string $field, array $fileDataList, callable $restCall): array
{
    return outgoingWebhookService('dealFiles')->updateDealFiles($dealId, $field, $fileDataList, $restCall);
}

function outgoingWebhookLogActivityFirst(array $entry): void
{
    outgoingWebhookService('taskDetails')->logActivityFirst($entry);
}

function outgoingWebhookBuildCommentDetails(
    array $commentData,
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId,
    ?string $sourceMethod,
    ?array $taskData = null
): array {
    return outgoingWebhookService('commentDetails')->buildDetails(
        $commentData,
        $eventType,
        $requestId,
        $taskId,
        $commentId,
        $sourceMethod,
        $taskData
    );
}

function outgoingWebhookBuildCommentFallback(
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId
): array {
    return outgoingWebhookService('commentDetails')->buildFallback($eventType, $requestId, $taskId, $commentId);
}

function outgoingWebhookResolveCommentKind($authorId): string
{
    return outgoingWebhookService('commentDetails')->resolveKind($authorId);
}

function outgoingWebhookFormatCommentDetailsRu(array $details): string
{
    return outgoingWebhookService('commentDetails')->formatDetailsRu($details);
}

function outgoingWebhookWriteCommentDetailsRu(string $eventType, array $details): void
{
    outgoingWebhookService('commentDetails')->writeDetailsRu($eventType, $details);
}

function outgoingWebhookFindCommentItem($payload, string $commentId): ?array
{
    return outgoingWebhookService('commentDetails')->findCommentItem($payload, $commentId);
}

function outgoingWebhookFetchCommentDetails(callable $restCall, string $taskId, string $commentId): array
{
    return outgoingWebhookService('commentDetails')->fetchDetails($restCall, $taskId, $commentId);
}

function outgoingWebhookExtractChatId(array $taskData): ?string
{
    return outgoingWebhookService('commentDetails')->extractChatId($taskData);
}

function outgoingWebhookFindChatMessage(array $payload, string $messageId): ?array
{
    return outgoingWebhookService('commentDetails')->findChatMessage($payload, $messageId);
}

function outgoingWebhookFetchChatMessageDetails(callable $restCall, string $chatId, string $messageId): array
{
    return outgoingWebhookService('commentDetails')->fetchChatMessageDetails($restCall, $chatId, $messageId);
}

function outgoingWebhookBuildCommentDetailsFromChat(
    array $messageData,
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId,
    ?string $sourceMethod,
    ?array $taskData = null
): array {
    return outgoingWebhookService('commentDetails')->buildDetailsFromChat(
        $messageData,
        $eventType,
        $requestId,
        $taskId,
        $commentId,
        $sourceMethod,
        $taskData
    );
}

function outgoingWebhookWriteCommentEnriched(
    string $eventType,
    string $entityId,
    array $taskData,
    array $commentData,
    string $sourceMethod,
    string $rawPath,
    ?string $requestId
): void {
    outgoingWebhookService('commentDetails')->writeEnriched(
        $eventType,
        $entityId,
        $taskData,
        $commentData,
        $sourceMethod,
        $rawPath,
        $requestId
    );
}

function outgoingWebhookShouldWriteCommentEnriched(?string $taskId): bool
{
    return outgoingWebhookService('commentDetails')->shouldWriteEnriched($taskId);
}

function outgoingWebhookResolveEntityType(string $eventType): string
{
    return outgoingWebhookService('identity')->resolveEntityType($eventType);
}

function outgoingWebhookJsonResponse(int $statusCode, array $payload): void
{
    outgoingWebhookService('request')->jsonResponse($statusCode, $payload);
}
