<?php
declare(strict_types=1);

/**
 * Управление состоянием заданий очереди в БД
 *
 * Контракт совместим с JobStateService (файловая очередь).
 * Извлекает id задания из пути db://queue_jobs/{id} и вызывает QueueRepository.
 */
class DatabaseJobStateService
{
    private const PATH_PREFIX = 'db://queue_jobs/';

    private QueueRepository $queueRepository;
    private ErrorService $errors;
    private int $maxAttempts;
    private int $processingTimeout;

    public function __construct(
        QueueRepository $queueRepository,
        ErrorService $errors,
        int $maxAttempts,
        int $processingTimeout
    ) {
        $this->queueRepository = $queueRepository;
        $this->errors = $errors;
        $this->maxAttempts = $maxAttempts;
        $this->processingTimeout = $processingTimeout;
    }

    /**
     * Взять задание в работу (pending → processing в БД)
     */
    public function markProcessing(QueueJob $job): ?QueueJob
    {
        $jobId = $this->extractJobId($job->getPath());
        if ($jobId === null) {
            return null;
        }
        if (!$this->queueRepository->setProcessing($jobId)) {
            return null;
        }
        return $job;
    }

    /**
     * Отметить задание как выполненное
     */
    public function markDone(QueueJob $job): void
    {
        $jobId = $this->extractJobId($job->getPath());
        if ($jobId === null) {
            return;
        }
        $this->queueRepository->updateStatus($jobId, 'done', null);
    }

    /**
     * Отметить задание как проваленное
     */
    public function markFailed(QueueJob $job, string $reason, ?string $method = null): void
    {
        $jobId = $this->extractJobId($job->getPath());
        if ($jobId === null) {
            return;
        }
        $message = $method !== null ? "{$reason} ({$method})" : $reason;
        $this->queueRepository->updateStatus($jobId, 'failed', $message);
    }

    /**
     * Вернуть задание в очередь для повторной попытки (pending + increment attempt)
     */
    public function markRequeue(QueueJob $job): void
    {
        $jobId = $this->extractJobId($job->getPath());
        if ($jobId === null) {
            return;
        }
        $this->queueRepository->updateStatus($jobId, 'pending', null);
        $this->queueRepository->incrementAttempt($jobId);
    }

    /**
     * Вернуть зависшие задания (processing) в pending
     */
    public function recoverProcessing(): void
    {
        $this->queueRepository->resetStaleProcessing($this->processingTimeout);
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    private function extractJobId(string $path): ?int
    {
        if (!str_starts_with($path, self::PATH_PREFIX)) {
            return null;
        }
        $suffix = substr($path, strlen(self::PATH_PREFIX));
        $id = (int) $suffix;
        return $id > 0 ? $id : null;
    }
}
