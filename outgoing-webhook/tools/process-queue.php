<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

const OUTGOING_WEBHOOK_MAX_ATTEMPTS = 3;
const OUTGOING_WEBHOOK_PROCESSING_TIMEOUT = 900; // 15 minutes

function outgoingWebhookRestCall(string $method, array $params = []): array
{
    $client = new Bitrix24Client();
    return $client->call($method, $params);
}

function outgoingWebhookCountQueue(string $dir): int
{
    return count(glob($dir . '/*.json') ?: []);
}

function outgoingWebhookResolveMethod(string $eventType): ?string
{
    if (str_starts_with($eventType, 'ONCRMDEAL')) {
        return 'crm.deal.get';
    }
    if (str_starts_with($eventType, 'ONCRMLEAD')) {
        return 'crm.lead.get';
    }
    if (str_starts_with($eventType, 'ONCRMCONTACT')) {
        return 'crm.contact.get';
    }
    if (str_starts_with($eventType, 'ONCRMCOMPANY')) {
        return 'crm.company.get';
    }
    if (str_starts_with($eventType, 'ONCRMITEM')) {
        return 'crm.item.get';
    }
    if (str_starts_with($eventType, 'ONTASK')) {
        return 'tasks.task.get';
    }
    if (str_starts_with($eventType, 'ONUSER')) {
        return 'user.get';
    }
    if (str_starts_with($eventType, 'SONET_GROUP')) {
        return 'sonet_group.get';
    }
    if (str_starts_with($eventType, 'ONCRMUSERFIELD')) {
        return 'crm.userfield.list';
    }

    return null;
}

function outgoingWebhookReadDict(string $path, int $ttlSeconds): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $contents = json_decode((string) file_get_contents($path), true);
    if (!is_array($contents)) {
        return null;
    }

    $cachedAt = $contents['cachedAt'] ?? null;
    if (!is_string($cachedAt)) {
        return null;
    }

    $age = time() - strtotime($cachedAt);
    if ($age > $ttlSeconds) {
        return null;
    }

    return $contents['data'] ?? null;
}

function outgoingWebhookWriteDict(string $path, array $data): void
{
    outgoingWebhookWriteJson($path, [
        'cachedAt' => outgoingWebhookNow(),
        'data' => $data,
    ]);
}

function outgoingWebhookGetDict(
    string $name,
    string $method,
    array $params,
    int $ttlSeconds
): ?array {
    $dictPath = __DIR__ . '/../logs/dicts/' . $name . '.json';
    $cached = outgoingWebhookReadDict($dictPath, $ttlSeconds);
    if ($cached !== null) {
        return $cached;
    }

    $result = outgoingWebhookRestCall($method, $params);
    if (!empty($result['error'])) {
        outgoingWebhookLogError('Dict REST error', ['method' => $method, 'error' => $result['error']]);
        return null;
    }

    $data = $result['result'] ?? [];
    if (is_array($data)) {
        outgoingWebhookWriteDict($dictPath, $data);
        return $data;
    }

    return null;
}

function outgoingWebhookExtractEntityTypeId(array $payload): ?string
{
    $data = $payload['data'] ?? [];
    if (is_array($data)) {
        if (isset($data['FIELDS']['ENTITY_TYPE_ID'])) {
            return (string) $data['FIELDS']['ENTITY_TYPE_ID'];
        }
        if (isset($data['ENTITY_TYPE_ID'])) {
            return (string) $data['ENTITY_TYPE_ID'];
        }
    }

    return null;
}

function outgoingWebhookBuildEnriched(
    string $eventType,
    string $entityType,
    ?string $entityId,
    array $raw,
    string $rawPath
): array {
    $method = outgoingWebhookResolveMethod($eventType);
    if ($method === null) {
        return ['error' => 'unknown_event_type'];
    }

    $params = [];
    if ($method === 'crm.item.get') {
        $entityTypeId = outgoingWebhookExtractEntityTypeId($raw['payload'] ?? []);
        if ($entityTypeId === null || $entityId === null) {
            return ['error' => 'missing_entity_type_id'];
        }
        $params = ['entityTypeId' => $entityTypeId, 'id' => $entityId];
    } elseif ($method === 'crm.userfield.list') {
        $params = [];
    } else {
        if ($entityId === null) {
            return ['error' => 'missing_entity_id'];
        }
        $params = ['id' => $entityId];
    }

    $startedAt = microtime(true);
    $result = outgoingWebhookRestCall($method, $params);
    $elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);

    if (!empty($result['error'])) {
        return ['error' => 'rest_error', 'details' => $result['error']];
    }

    $data = [
        $entityType => $result['result'] ?? $result,
    ];

    $dictTtl = 86400;
    if ($entityType === 'deal') {
        $data['categories'] = outgoingWebhookGetDict(
            'deal_categories',
            'crm.category.list',
            ['entityTypeId' => 2],
            $dictTtl
        );
        $data['stages'] = outgoingWebhookGetDict(
            'deal_stages',
            'crm.status.list',
            ['filter' => ['ENTITY_ID' => 'DEAL_STAGE']],
            $dictTtl
        );
    } elseif ($entityType === 'lead') {
        $data['stages'] = outgoingWebhookGetDict(
            'lead_stages',
            'crm.status.list',
            ['filter' => ['ENTITY_ID' => 'STATUS']],
            $dictTtl
        );
    } elseif ($entityType === 'smart_process') {
        $entityTypeId = outgoingWebhookExtractEntityTypeId($raw['payload'] ?? []);
        if ($entityTypeId !== null) {
            $data['types'] = outgoingWebhookGetDict(
                'crm_types',
                'crm.type.list',
                [],
                $dictTtl
            );
            $data['categories'] = outgoingWebhookGetDict(
                'crm_item_categories_' . $entityTypeId,
                'crm.category.list',
                ['entityTypeId' => $entityTypeId],
                $dictTtl
            );
        }
    }

    return [
        'eventType' => $eventType,
        'entityType' => $entityType,
        'entityId' => $entityId,
        'enrichedAt' => outgoingWebhookNow(),
        'source' => [
            'method' => $method,
            'responseTimeMs' => $elapsedMs,
        ],
        'rawRef' => $rawPath,
        'data' => $data,
    ];
}

function outgoingWebhookMoveToFailed(string $processingPath, array $job, string $reason, ?string $method = null): void
{
    $failedPath = __DIR__ . '/../queue/failed/' . basename($processingPath);
    $errorPath = $failedPath . '.error.json';

    outgoingWebhookWriteJson($failedPath, $job);
    outgoingWebhookWriteJson($errorPath, [
        'failedAt' => outgoingWebhookNow(),
        'reason' => $reason,
        'attempt' => $job['attempt'] ?? 0,
        'lastMethod' => $method,
    ]);
}

function outgoingWebhookSortRecursive($value)
{
    if (!is_array($value)) {
        return $value;
    }

    $isAssoc = array_keys($value) !== range(0, count($value) - 1);
    if ($isAssoc) {
        ksort($value);
    } else {
        sort($value);
    }

    foreach ($value as $key => $item) {
        $value[$key] = outgoingWebhookSortRecursive($item);
    }

    return $value;
}

function outgoingWebhookValuesEqual($left, $right): bool
{
    return json_encode(outgoingWebhookSortRecursive($left)) === json_encode(outgoingWebhookSortRecursive($right));
}

function outgoingWebhookGetStatePath(string $entityType, string $entityId): string
{
    return __DIR__ . '/../logs/state/' . $entityType . '_' . $entityId . '.json';
}

function outgoingWebhookDetectFieldChanges(string $entityType, string $entityId, array $current, string $eventType): void
{
    $statePath = outgoingWebhookGetStatePath($entityType, $entityId);
    $previous = null;
    if (file_exists($statePath)) {
        $previous = json_decode((string) file_get_contents($statePath), true);
    }

    if (is_array($previous) && isset($previous['data']) && is_array($previous['data'])) {
        $before = $previous['data'];
        $after = $current;
        $fields = array_unique(array_merge(array_keys($before), array_keys($after)));

        $changes = [];
        foreach ($fields as $field) {
            $oldValue = $before[$field] ?? null;
            $newValue = $after[$field] ?? null;
            if (!outgoingWebhookValuesEqual($oldValue, $newValue)) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        if (!empty($changes)) {
            $changesDir = __DIR__ . '/../logs/field-changes';
            outgoingWebhookSafeMkdir($changesDir);

            $entry = [
                'changedAt' => outgoingWebhookNow(),
                'eventType' => $eventType,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'changes' => $changes,
            ];

            $changeFile = sprintf(
                '%s/%s_%s_%s.json',
                $changesDir,
                $entityType,
                $entityId,
                date('Ymd_His')
            );

            outgoingWebhookWriteJson($changeFile, $entry);
            outgoingWebhookAppendLine($changesDir . '/field-changes.log', json_encode($entry, JSON_UNESCAPED_SLASHES));
        }
    }

    outgoingWebhookWriteJson($statePath, [
        'savedAt' => outgoingWebhookNow(),
        'entityType' => $entityType,
        'entityId' => $entityId,
        'data' => $current,
    ]);
}

function outgoingWebhookRecoverProcessing(): void
{
    $processingDir = __DIR__ . '/../queue/processing';
    $pendingDir = __DIR__ . '/../queue/pending';
    $failedDir = __DIR__ . '/../queue/failed';

    foreach (glob($processingDir . '/*.json') ?: [] as $file) {
        $age = time() - filemtime($file);
        if ($age <= OUTGOING_WEBHOOK_PROCESSING_TIMEOUT) {
            continue;
        }

        $job = json_decode((string) file_get_contents($file), true);
        if (!is_array($job)) {
            $job = ['attempt' => OUTGOING_WEBHOOK_MAX_ATTEMPTS];
        }

        $job['attempt'] = (int) ($job['attempt'] ?? 0) + 1;
        if ($job['attempt'] >= OUTGOING_WEBHOOK_MAX_ATTEMPTS) {
            $failedPath = $failedDir . '/' . basename($file);
            rename($file, $failedPath);
            outgoingWebhookMoveToFailed($failedPath, $job, 'processing_timeout');
            continue;
        }

        outgoingWebhookWriteJson($file, $job);
        rename($file, $pendingDir . '/' . basename($file));
    }
}

outgoingWebhookRecoverProcessing();

$pendingDir = __DIR__ . '/../queue/pending';
$processingDir = __DIR__ . '/../queue/processing';
$doneDir = __DIR__ . '/../queue/done';
$failedDir = __DIR__ . '/../queue/failed';

$limit = (int) ($_GET['limit'] ?? 50);
if ($limit <= 0) {
    $limit = 50;
}

$startedAt = microtime(true);
$processed = 0;
foreach (array_slice(glob($pendingDir . '/*.json') ?: [], 0, $limit) as $file) {
    $processingPath = $processingDir . '/' . basename($file);
    if (!rename($file, $processingPath)) {
        continue;
    }

    $job = json_decode((string) file_get_contents($processingPath), true);
    if (!is_array($job)) {
        outgoingWebhookMoveToFailed($processingPath, ['attempt' => OUTGOING_WEBHOOK_MAX_ATTEMPTS], 'invalid_job');
        @unlink($processingPath);
        continue;
    }

    $job['attempt'] = (int) ($job['attempt'] ?? 0) + 1;

    $eventType = (string) ($job['eventType'] ?? 'UNKNOWN');
    $rawPath = (string) ($job['rawPath'] ?? '');
    $raw = [];
    if (isset($job['payload']) && is_array($job['payload'])) {
        $raw = ['payload' => $job['payload']];
    } elseif ($rawPath !== '' && file_exists($rawPath)) {
        $raw = json_decode((string) file_get_contents($rawPath), true);
    }
    if (!is_array($raw)) {
        $raw = [];
    }

    $entityId = $job['entityId'] ?? outgoingWebhookExtractEntityId($raw['payload'] ?? []);
    $entityId = outgoingWebhookNormalizeEntityId(is_string($entityId) ? $entityId : (string) $entityId);
    if ($entityId === null && str_starts_with($eventType, 'ONTASKCOMMENT')) {
        $entityId = outgoingWebhookNormalizeEntityId(outgoingWebhookExtractTaskId($raw['payload'] ?? []));
    }
    $entityType = $job['entityType'] ?? outgoingWebhookResolveEntityType($eventType);

    $enriched = outgoingWebhookBuildEnriched($eventType, $entityType, $entityId, $raw, $rawPath);
    if (!empty($enriched['error'])) {
        if ($job['attempt'] >= OUTGOING_WEBHOOK_MAX_ATTEMPTS) {
            outgoingWebhookMoveToFailed($processingPath, $job, $enriched['error'], $enriched['details'] ?? null);
            @unlink($processingPath);
        } else {
            outgoingWebhookWriteJson($processingPath, $job);
            rename($processingPath, $pendingDir . '/' . basename($processingPath));
        }
        continue;
    }

    $eventDir = __DIR__ . '/../logs/' . $eventType;
    $enrichedPath = $eventDir . '/enriched.json';
    if (!outgoingWebhookWriteJson($enrichedPath, $enriched)) {
        outgoingWebhookLogError('Failed to write enriched.json', ['path' => $enrichedPath]);
    }

    $taskData = outgoingWebhookExtractTaskData($enriched);
    if (is_array($taskData)) {
        $details = outgoingWebhookBuildTaskDetails($taskData, $eventType, $job['requestId'] ?? null, $entityId);
        outgoingWebhookWriteTaskDetailsRu($eventType, $details);
    }

    if ($eventType === 'ONTASKCOMMENTADD') {
        $commentId = outgoingWebhookExtractCommentId($raw['payload'] ?? []);
        $messageId = outgoingWebhookExtractMessageId($raw['payload'] ?? []);
        if ($commentId !== null && $entityId !== null) {
            $commentWritten = false;
            $commentData = null;
            $commentSource = null;
            $commentDetails = null;
            $fetch = outgoingWebhookFetchCommentDetails('outgoingWebhookRestCall', $entityId, $commentId);
            if (is_array($fetch['data'])) {
                if (is_array($taskData)) {
                    $taskData = outgoingWebhookEnsureTaskCrmLinks($taskData, $entityId, 'outgoingWebhookRestCall');
                }
                $commentData = $fetch['data'];
                $commentSource = $fetch['method'];
                $commentDetails = outgoingWebhookBuildCommentDetails(
                    $fetch['data'],
                    $eventType,
                    $job['requestId'] ?? null,
                    $entityId,
                    $commentId,
                    $fetch['method'],
                    is_array($taskData) ? $taskData : null
                );
                outgoingWebhookWriteCommentDetailsRu($eventType, $commentDetails);
                $commentWritten = true;
            } else {
                if (is_array($taskData)) {
                    $taskData = outgoingWebhookEnsureTaskCrmLinks($taskData, $entityId, 'outgoingWebhookRestCall');
                    $chatId = outgoingWebhookExtractChatId($taskData);
                    if ($chatId !== null && $messageId !== null) {
                        $chatFetch = outgoingWebhookFetchChatMessageDetails('outgoingWebhookRestCall', $chatId, $messageId);
                        if (is_array($chatFetch['data'])) {
                            $commentData = $chatFetch['data'];
                            $commentSource = $chatFetch['method'];
                            $commentDetails = outgoingWebhookBuildCommentDetailsFromChat(
                                $chatFetch['data'],
                                $eventType,
                                $job['requestId'] ?? null,
                                $entityId,
                                $commentId,
                                $chatFetch['method'],
                                $taskData
                            );
                            outgoingWebhookWriteCommentDetailsRu($eventType, $commentDetails);
                            $commentWritten = true;
                        }
                    }
                }

                if (!$commentWritten) {
                    $fallback = outgoingWebhookBuildCommentFallback(
                        $eventType,
                        $job['requestId'] ?? null,
                        $entityId,
                        $commentId
                    );
                    outgoingWebhookWriteCommentDetailsRu($eventType, $fallback);
                    outgoingWebhookLogError('Comment details missing', [
                        'requestId' => $job['requestId'] ?? 'unknown',
                        'taskId' => $entityId,
                        'commentId' => $commentId,
                        'messageId' => $messageId,
                        'errors' => $fetch['errors'] ?? [],
                    ]);
                }
            }

            if ($commentWritten && outgoingWebhookShouldWriteCommentEnriched($entityId) && is_array($commentData)) {
                if (!is_array($taskData)) {
                    $taskData = outgoingWebhookExtractTaskData($enriched);
                }
                if (is_array($taskData)) {
                    outgoingWebhookWriteCommentEnriched(
                        $eventType,
                        $entityId,
                        $taskData,
                        $commentData,
                        $commentSource ?? 'unknown',
                        $rawPath,
                        $job['requestId'] ?? null
                    );
                }
            }

            if ($commentWritten && is_array($commentDetails) && !empty($commentDetails['activityFirst'])) {
                $fileIds = $commentDetails['fileIds'] ?? [];
                $fileIds = is_array($fileIds) ? array_values(array_unique($fileIds)) : [];
                $dealIds = outgoingWebhookExtractDealIds($commentDetails['crmLinks'] ?? []);

                $taskAttach = ['attached' => [], 'errors' => []];
                if (!empty($fileIds)) {
                    $taskAttach = outgoingWebhookAttachFilesToTask($entityId, $fileIds, 'outgoingWebhookRestCall');
                }

                $dealUpdates = [];
                $fileDataList = [];
                foreach ($fileIds as $fileId) {
                    $fileData = outgoingWebhookBuildDealFileData($fileId, 'outgoingWebhookRestCall');
                    if ($fileData !== null) {
                        $fileDataList[] = ['fileData' => $fileData];
                    }
                }

                foreach ($dealIds as $dealId) {
                    $dealUpdates[] = array_merge(
                        ['dealId' => $dealId],
                        outgoingWebhookUpdateDealFiles($dealId, 'UF_CRM_1759233362672', $fileDataList, 'outgoingWebhookRestCall')
                    );
                }

                outgoingWebhookLogActivityFirst([
                    'loggedAt' => outgoingWebhookNow(),
                    'requestId' => $job['requestId'] ?? 'unknown',
                    'taskId' => $entityId,
                    'dealIds' => $dealIds,
                    'fileIds' => $fileIds,
                    'taskAttach' => $taskAttach,
                    'dealUpdates' => $dealUpdates,
                ]);
            }
        }
    }

    if ($entityId !== null && isset($enriched['data'][$entityType]) && is_array($enriched['data'][$entityType])) {
        outgoingWebhookDetectFieldChanges($entityType, $entityId, $enriched['data'][$entityType], $eventType);
    }

    outgoingWebhookWriteJson($processingPath, $job);
    rename($processingPath, $doneDir . '/' . basename($processingPath));
    $processed++;
}

$elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);
outgoingWebhookJsonResponse(200, [
    'processed' => $processed,
    'processingMs' => $elapsedMs,
    'queue' => [
        'pending' => outgoingWebhookCountQueue($pendingDir),
        'processing' => outgoingWebhookCountQueue($processingDir),
        'done' => outgoingWebhookCountQueue($doneDir),
        'failed' => outgoingWebhookCountQueue($failedDir),
    ],
]);
