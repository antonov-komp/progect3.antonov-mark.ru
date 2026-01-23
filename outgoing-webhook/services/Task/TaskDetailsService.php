<?php
declare(strict_types=1);

class TaskDetailsService
{
    public function writeDetails(string $eventType, array $enriched, ?string $requestId, ?string $entityId): ?array
    {
        $taskData = outgoingWebhookExtractTaskData($enriched);
        if (!is_array($taskData)) {
            return null;
        }

        $details = outgoingWebhookBuildTaskDetails($taskData, $eventType, $requestId, $entityId);
        outgoingWebhookWriteTaskDetailsRu($eventType, $details);

        return $taskData;
    }
}
