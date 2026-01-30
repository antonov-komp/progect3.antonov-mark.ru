<?php
declare(strict_types=1);

class JobStateService
{
    private QueueService $queue;
    private ErrorService $errors;
    private int $maxAttempts;
    private int $processingTimeout;

    public function __construct(QueueService $queue, ErrorService $errors, int $maxAttempts, int $processingTimeout)
    {
        $this->queue = $queue;
        $this->errors = $errors;
        $this->maxAttempts = $maxAttempts;
        $this->processingTimeout = $processingTimeout;
    }

    public function markProcessing(QueueJob $job): ?QueueJob
    {
        $processingPath = $this->queue->getProcessingDir() . '/' . $job->getName();
        if (!rename($job->getPath(), $processingPath)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($processingPath), true);
        $isValid = is_array($data);

        return new QueueJob($processingPath, $isValid ? $data : [], $isValid);
    }

    public function markDone(QueueJob $job): void
    {
        outgoingWebhookWriteJson($job->getPath(), $job->getData());
        rename($job->getPath(), $this->queue->getDoneDir() . '/' . $job->getName());
    }

    public function markFailed(QueueJob $job, string $reason, ?string $method = null): void
    {
        $failedPath = $this->queue->getFailedDir() . '/' . $job->getName();
        $errorPath = $failedPath . '.error.json';

        outgoingWebhookWriteJson($failedPath, $job->getData());
        outgoingWebhookWriteJson($errorPath, [
            'failedAt' => outgoingWebhookNow(),
            'reason' => $reason,
            'attempt' => $job->getData()['attempt'] ?? 0,
            'lastMethod' => $method,
        ]);
    }

    /**
     * Вернуть задание в очередь для повторной попытки (записать в pending)
     */
    public function markRequeue(QueueJob $job): void
    {
        $pendingPath = $this->queue->getPendingDir() . '/' . $job->getName();
        outgoingWebhookWriteJson($pendingPath, $job->getData());
        @unlink($job->getPath());
    }

    public function recoverProcessing(): void
    {
        $processingDir = $this->queue->getProcessingDir();
        $pendingDir = $this->queue->getPendingDir();
        $failedDir = $this->queue->getFailedDir();

        foreach (glob($processingDir . '/*.json') ?: [] as $file) {
            $age = time() - filemtime($file);
            if ($age <= $this->processingTimeout) {
                continue;
            }

            $job = json_decode((string) file_get_contents($file), true);
            if (!is_array($job)) {
                $job = ['attempt' => $this->maxAttempts];
            }

            $job['attempt'] = (int) ($job['attempt'] ?? 0) + 1;
            if ($job['attempt'] >= $this->maxAttempts) {
                $failedPath = $failedDir . '/' . basename($file);
                rename($file, $failedPath);
                $this->markFailed(new QueueJob($failedPath, $job), 'processing_timeout');
                continue;
            }

            outgoingWebhookWriteJson($file, $job);
            rename($file, $pendingDir . '/' . basename($file));
        }
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
