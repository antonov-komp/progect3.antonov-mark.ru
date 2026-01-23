<?php
declare(strict_types=1);

class QueueRunner
{
    private QueueService $queue;
    private JobStateService $jobState;
    private EnrichmentService $enrichment;
    private ErrorService $errors;
    private QueueStepLogger $steps;
    private TaskDetailsService $taskDetails;
    private CommentDetailsService $commentDetails;

    public function __construct(
        QueueService $queue,
        JobStateService $jobState,
        EnrichmentService $enrichment,
        ErrorService $errors,
        QueueStepLogger $steps,
        TaskDetailsService $taskDetails,
        CommentDetailsService $commentDetails
    ) {
        $this->queue = $queue;
        $this->jobState = $jobState;
        $this->enrichment = $enrichment;
        $this->errors = $errors;
        $this->steps = $steps;
        $this->taskDetails = $taskDetails;
        $this->commentDetails = $commentDetails;
    }

    public function run(int $limit): array
    {
        $this->jobState->recoverProcessing();

        $startedAt = microtime(true);
        $processed = 0;

        foreach ($this->queue->listPending($limit) as $pendingJob) {
            $processingJob = $this->jobState->markProcessing($pendingJob);
            if ($processingJob === null) {
                continue;
            }

            if (!$processingJob->isValid()) {
                $failedJob = new QueueJob($processingJob->getPath(), ['attempt' => $this->jobState->getMaxAttempts()]);
                $this->jobState->markFailed($failedJob, 'invalid_job');
                @unlink($processingJob->getPath());
                continue;
            }
            $job = $processingJob->getData();

            $job['attempt'] = (int) ($job['attempt'] ?? 0) + 1;
            $processingJob->setData($job);

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

            $this->steps->started([
                'requestId' => $job['requestId'] ?? 'unknown',
                'eventType' => $eventType,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'attempt' => $job['attempt'] ?? 0,
                'jobFile' => $processingJob->getName(),
            ]);

            $enriched = $this->enrichment->buildEnriched($eventType, $entityType, $entityId, $raw, $rawPath);
            if (!empty($enriched['error'])) {
                if ($job['attempt'] >= $this->jobState->getMaxAttempts()) {
                    $this->jobState->markFailed($processingJob, $enriched['error'], $enriched['details'] ?? null);
                    @unlink($processingJob->getPath());
                } else {
                    outgoingWebhookWriteJson($processingJob->getPath(), $job);
                    rename($processingJob->getPath(), $this->queue->getPendingDir() . '/' . $processingJob->getName());
                }

                $this->steps->finished([
                    'requestId' => $job['requestId'] ?? 'unknown',
                    'eventType' => $eventType,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'attempt' => $job['attempt'] ?? 0,
                    'jobFile' => $processingJob->getName(),
                    'status' => 'failed',
                    'error' => $enriched['error'],
                ]);
                continue;
            }

            $eventDir = __DIR__ . '/../../logs/' . $eventType;
            $enrichedPath = $eventDir . '/enriched.json';
            if (!outgoingWebhookWriteJson($enrichedPath, $enriched)) {
                $this->errors->log('Failed to write enriched.json', ['path' => $enrichedPath]);
            }

            $taskData = $this->taskDetails->writeDetails($eventType, $enriched, $job['requestId'] ?? null, $entityId);

            if ($eventType === 'ONTASKCOMMENTADD') {
                $this->commentDetails->handleCommentAdd(
                    $eventType,
                    $raw,
                    $job,
                    $entityId,
                    $rawPath,
                    $taskData
                );
            }

            if ($entityId !== null && isset($enriched['data'][$entityType]) && is_array($enriched['data'][$entityType])) {
                $this->enrichment->detectFieldChanges($entityType, $entityId, $enriched['data'][$entityType], $eventType);
            }

            $this->jobState->markDone($processingJob);
            $processed++;

            $this->steps->finished([
                'requestId' => $job['requestId'] ?? 'unknown',
                'eventType' => $eventType,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'attempt' => $job['attempt'] ?? 0,
                'jobFile' => $processingJob->getName(),
                'status' => 'done',
            ]);
        }

        $elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);

        return [
            'processed' => $processed,
            'processingMs' => $elapsedMs,
            'queue' => [
                'pending' => $this->queue->count($this->queue->getPendingDir()),
                'processing' => $this->queue->count($this->queue->getProcessingDir()),
                'done' => $this->queue->count($this->queue->getDoneDir()),
                'failed' => $this->queue->count($this->queue->getFailedDir()),
            ],
        ];
    }
}
