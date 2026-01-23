<?php
declare(strict_types=1);

class CommentDetailsService
{
    private RestService $rest;
    private ErrorService $errors;

    public function __construct(RestService $rest, ErrorService $errors)
    {
        $this->rest = $rest;
        $this->errors = $errors;
    }

    public function handleCommentAdd(
        string $eventType,
        array $raw,
        array $job,
        ?string $entityId,
        string $rawPath,
        ?array $taskData
    ): void {
        $commentId = outgoingWebhookExtractCommentId($raw['payload'] ?? []);
        $messageId = outgoingWebhookExtractMessageId($raw['payload'] ?? []);
        if ($commentId === null || $entityId === null) {
            return;
        }

        $commentWritten = false;
        $commentData = null;
        $commentSource = null;
        $commentDetails = null;
        $restCall = fn(string $method, array $params = []) => $this->rest->call($method, $params);

        $fetch = outgoingWebhookFetchCommentDetails($restCall, $entityId, $commentId);
        if (is_array($fetch['data'])) {
            if (is_array($taskData)) {
                $taskData = outgoingWebhookEnsureTaskCrmLinks($taskData, $entityId, $restCall);
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
                $taskData = outgoingWebhookEnsureTaskCrmLinks($taskData, $entityId, $restCall);
                $chatId = outgoingWebhookExtractChatId($taskData);
                if ($chatId !== null && $messageId !== null) {
                    $chatFetch = outgoingWebhookFetchChatMessageDetails($restCall, $chatId, $messageId);
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
                $this->errors->log('Comment details missing', [
                    'requestId' => $job['requestId'] ?? 'unknown',
                    'taskId' => $entityId,
                    'commentId' => $commentId,
                    'messageId' => $messageId,
                    'errors' => $fetch['errors'] ?? [],
                ]);
            }
        }

        if ($commentWritten && outgoingWebhookShouldWriteCommentEnriched($entityId) && is_array($commentData)) {
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
                $taskAttach = outgoingWebhookAttachFilesToTask($entityId, $fileIds, $restCall);
            }

            $dealUpdates = [];
            $fileDataList = [];
            foreach ($fileIds as $fileId) {
                $fileData = outgoingWebhookBuildDealFileData($fileId, $restCall);
                if ($fileData !== null) {
                    $fileDataList[] = ['fileData' => $fileData];
                }
            }

            foreach ($dealIds as $dealId) {
                $dealUpdates[] = array_merge(
                    ['dealId' => $dealId],
                    outgoingWebhookUpdateDealFiles($dealId, 'UF_CRM_1759233362672', $fileDataList, $restCall)
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
