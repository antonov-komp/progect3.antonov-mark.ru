<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    outgoingWebhookJsonResponse(405, ['error' => 'method_not_allowed']);
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > OUTGOING_WEBHOOK_MAX_BYTES) {
    outgoingWebhookJsonResponse(413, ['error' => 'payload_too_large']);
    exit;
}

$payload = outgoingWebhookReadPayload();
if (empty($payload)) {
    outgoingWebhookLogError('Empty or invalid payload', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    outgoingWebhookJsonResponse(400, ['error' => 'invalid_payload']);
    exit;
}

$requestId = outgoingWebhookGenerateRequestId();
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$allowedIps = outgoingWebhookGetAllowedIps();
if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
    outgoingWebhookLogError('IP not allowed', ['ip' => $clientIp, 'requestId' => $requestId]);
    outgoingWebhookJsonResponse(403, ['error' => 'ip_not_allowed']);
    exit;
}

$expectedToken = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN');
if ($expectedToken === null) {
    outgoingWebhookLogError('Missing OUTGOING_WEBHOOK_TOKEN env');
    outgoingWebhookJsonResponse(500, ['error' => 'server_not_configured']);
    exit;
}

$authInfo = outgoingWebhookExtractAuthInfo($payload);
if (!hash_equals($expectedToken, $authInfo['token'])) {
    outgoingWebhookLogError('Invalid token', [
        'ip' => $clientIp,
        'requestId' => $requestId,
        'token_source' => $authInfo['source'],
    ]);
    outgoingWebhookJsonResponse(403, ['error' => 'invalid_token']);
    exit;
}

$eventType = outgoingWebhookNormalizeEventType((string) ($payload['event'] ?? ($payload['eventName'] ?? '')));
$entityId = outgoingWebhookNormalizeEntityId(outgoingWebhookExtractEntityId($payload));
if (str_starts_with($eventType, 'ONTASKCOMMENT') && $entityId === null) {
    $entityId = outgoingWebhookNormalizeEntityId(outgoingWebhookExtractTaskId($payload));
}
$entityType = outgoingWebhookResolveEntityType($eventType);
$eventHandlerId = (string) ($payload['event_handler_id'] ?? 'unknown');
$memberId = (string) ($payload['auth']['member_id'] ?? 'unknown');

$eventDir = __DIR__ . '/logs/' . $eventType;
outgoingWebhookSafeMkdir($eventDir);

$maskedPayload = outgoingWebhookMaskPayload($payload);
$raw = [
    'requestId' => $requestId,
    'eventType' => $eventType,
    'receivedAt' => outgoingWebhookNow(),
    'ip' => $clientIp,
    'tokenSource' => $authInfo['source'],
    'eventHandlerId' => $eventHandlerId,
    'memberId' => $memberId,
    'payload' => $maskedPayload,
];

$rawPath = $eventDir . '/raw.json';
if (!outgoingWebhookWriteJson($rawPath, $raw)) {
    outgoingWebhookLogError('Failed to write raw.json', ['path' => $rawPath]);
}

$eventLogPath = $eventDir . '/event.log';
$eventLogLine = sprintf(
    '%s | IP=%s | requestId=%s | event=%s | entityType=%s | entityId=%s | eventHandlerId=%s | memberId=%s | tokenSource=%s',
    outgoingWebhookNow(),
    $clientIp,
    $requestId,
    $eventType,
    $entityType,
    $entityId ?? 'unknown',
    $eventHandlerId,
    $memberId,
    $authInfo['source']
);
if (!outgoingWebhookAppendLine($eventLogPath, $eventLogLine)) {
    outgoingWebhookLogError('Failed to write event.log', ['path' => $eventLogPath]);
}

$queueItem = [
    'requestId' => $requestId,
    'eventType' => $eventType,
    'entityType' => $entityType,
    'entityId' => $entityId,
    'rawPath' => $rawPath,
    'createdAt' => outgoingWebhookNow(),
    'attempt' => 0,
    'priority' => 'normal',
    'source' => 'outgoing-webhook',
    'tokenSource' => $authInfo['source'],
    'eventHandlerId' => $eventHandlerId,
    'memberId' => $memberId,
    'payload' => $maskedPayload,
];

$queueName = sprintf(
    '%s_%s_%s.json',
    date('Ymd_His'),
    $eventType,
    $entityId ?? 'unknown'
);
$queuePath = __DIR__ . '/queue/pending/' . $queueName;
if (!outgoingWebhookWriteJson($queuePath, $queueItem)) {
    outgoingWebhookLogError('Failed to enqueue item', ['path' => $queuePath]);
}

if ($entityId !== null && str_starts_with($eventType, 'ONTASK')) {
    try {
        require_once __DIR__ . '/../app/crest.php';
        require_once __DIR__ . '/../app/Services/Bitrix24Client.php';
        $client = new Bitrix24Client();
        $result = $client->call('tasks.task.get', ['id' => $entityId]);
        if (!empty($result['error'])) {
            outgoingWebhookLogError('Task details REST error', [
                'requestId' => $requestId,
                'taskId' => $entityId,
                'error' => $result['error'],
            ]);
        } else {
            $taskPayload = $result['result'] ?? $result;
            if (is_array($taskPayload)) {
                $taskData = $taskPayload['task'] ?? $taskPayload;
                if (is_array($taskData)) {
                    $details = outgoingWebhookBuildTaskDetails($taskData, $eventType, $requestId, $entityId);
                    outgoingWebhookWriteTaskDetailsRu($eventType, $details);
                }
            }
        }
    } catch (Throwable $e) {
        outgoingWebhookLogError('Task details exception', [
            'requestId' => $requestId,
            'taskId' => $entityId,
            'message' => $e->getMessage(),
        ]);
    }
}

if ($entityId !== null && $eventType === 'ONTASKCOMMENTADD') {
    $commentId = outgoingWebhookExtractCommentId($payload);
    $messageId = outgoingWebhookExtractMessageId($payload);
    if ($commentId !== null) {
        $commentWritten = false;
        $commentData = null;
        $commentSource = null;
        $taskData = null;
        try {
            require_once __DIR__ . '/../app/crest.php';
            require_once __DIR__ . '/../app/Services/Bitrix24Client.php';
            $client = new Bitrix24Client();
            $fetch = outgoingWebhookFetchCommentDetails([$client, 'call'], $entityId, $commentId);
            if (is_array($fetch['data'])) {
                if ($taskData === null) {
                    $taskResult = $client->call('tasks.task.get', ['id' => $entityId]);
                    $taskPayload = $taskResult['result'] ?? $taskResult;
                    $taskData = is_array($taskPayload) ? ($taskPayload['task'] ?? $taskPayload) : null;
                }
                if (is_array($taskData)) {
                    $taskData = outgoingWebhookEnsureTaskCrmLinks($taskData, $entityId, [$client, 'call']);
                }
                $commentData = $fetch['data'];
                $commentSource = $fetch['method'];
                $details = outgoingWebhookBuildCommentDetails(
                    $fetch['data'],
                    $eventType,
                    $requestId,
                    $entityId,
                    $commentId,
                    $fetch['method'],
                    $taskData
                );
                outgoingWebhookWriteCommentDetailsRu($eventType, $details);
                $commentWritten = true;
            } else {
                if ($messageId !== null) {
                    $taskResult = $client->call('tasks.task.get', ['id' => $entityId]);
                    $taskPayload = $taskResult['result'] ?? $taskResult;
                    $taskData = is_array($taskPayload) ? ($taskPayload['task'] ?? $taskPayload) : null;
                    if (is_array($taskData)) {
                        $taskData = outgoingWebhookEnsureTaskCrmLinks($taskData, $entityId, [$client, 'call']);
                        $chatId = outgoingWebhookExtractChatId($taskData);
                        if ($chatId !== null) {
                            $chatFetch = outgoingWebhookFetchChatMessageDetails([$client, 'call'], $chatId, $messageId);
                            if (is_array($chatFetch['data'])) {
                                $commentData = $chatFetch['data'];
                                $commentSource = $chatFetch['method'];
                                $chatMessage = outgoingWebhookBuildCommentDetailsFromChat(
                                    $chatFetch['data'],
                                    $eventType,
                                    $requestId,
                                    $entityId,
                                    $commentId,
                                    $chatFetch['method'],
                                    $taskData
                                );
                                outgoingWebhookWriteCommentDetailsRu($eventType, $chatMessage);
                                $commentWritten = true;
                            }
                        }
                    }
                }

                if (!$commentWritten) {
                    $fallback = outgoingWebhookBuildCommentFallback($eventType, $requestId, $entityId, $commentId);
                    outgoingWebhookWriteCommentDetailsRu($eventType, $fallback);
                    outgoingWebhookLogError('Comment details missing', [
                        'requestId' => $requestId,
                        'taskId' => $entityId,
                        'commentId' => $commentId,
                        'messageId' => $messageId,
                        'errors' => $fetch['errors'] ?? [],
                    ]);
                }
            }

            if ($commentWritten && outgoingWebhookShouldWriteCommentEnriched($entityId) && is_array($commentData)) {
                if ($taskData === null) {
                    $taskResult = $client->call('tasks.task.get', ['id' => $entityId]);
                    $taskPayload = $taskResult['result'] ?? $taskResult;
                    $taskData = is_array($taskPayload) ? ($taskPayload['task'] ?? $taskPayload) : null;
                }
                if (is_array($taskData)) {
                    $rawPath = $rawPath ?? '';
                    outgoingWebhookWriteCommentEnriched(
                        $eventType,
                        $entityId,
                        $taskData,
                        $commentData,
                        $commentSource ?? 'unknown',
                        $rawPath,
                        $requestId
                    );
                }
            }
        } catch (Throwable $e) {
            outgoingWebhookLogError('Comment details exception', [
                'requestId' => $requestId,
                'taskId' => $entityId,
                'commentId' => $commentId,
                'messageId' => $messageId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

outgoingWebhookJsonResponse(200, ['status' => 'ok']);
