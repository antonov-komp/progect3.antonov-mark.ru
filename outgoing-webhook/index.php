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

$expectedToken = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN');
if ($expectedToken === null) {
    outgoingWebhookLogError('Missing OUTGOING_WEBHOOK_TOKEN env');
    outgoingWebhookJsonResponse(500, ['error' => 'server_not_configured']);
    exit;
}

$token = outgoingWebhookExtractAuthToken($payload);
if (!hash_equals($expectedToken, $token)) {
    outgoingWebhookLogError('Invalid token', [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'token_source' => array_keys($payload),
    ]);
    outgoingWebhookJsonResponse(403, ['error' => 'invalid_token']);
    exit;
}

$eventType = outgoingWebhookNormalizeEventType((string) ($payload['event'] ?? ($payload['eventName'] ?? '')));
$entityId = outgoingWebhookExtractEntityId($payload);
$entityType = outgoingWebhookResolveEntityType($eventType);

$eventDir = __DIR__ . '/logs/' . $eventType;
outgoingWebhookSafeMkdir($eventDir);

$maskedPayload = outgoingWebhookMaskPayload($payload);
$raw = [
    'eventType' => $eventType,
    'receivedAt' => outgoingWebhookNow(),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'payload' => $maskedPayload,
];

$rawPath = $eventDir . '/raw.json';
if (!outgoingWebhookWriteJson($rawPath, $raw)) {
    outgoingWebhookLogError('Failed to write raw.json', ['path' => $rawPath]);
}

$eventLogPath = $eventDir . '/event.log';
$eventLogLine = sprintf(
    '%s | IP=%s | event=%s | entityType=%s | entityId=%s',
    outgoingWebhookNow(),
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $eventType,
    $entityType,
    $entityId ?? 'unknown'
);
if (!outgoingWebhookAppendLine($eventLogPath, $eventLogLine)) {
    outgoingWebhookLogError('Failed to write event.log', ['path' => $eventLogPath]);
}

$queueItem = [
    'eventType' => $eventType,
    'entityType' => $entityType,
    'entityId' => $entityId,
    'rawPath' => $rawPath,
    'createdAt' => outgoingWebhookNow(),
    'attempt' => 0,
    'priority' => 'normal',
    'source' => 'outgoing-webhook',
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

outgoingWebhookJsonResponse(200, ['status' => 'ok']);
