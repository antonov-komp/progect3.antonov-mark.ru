<?php
declare(strict_types=1);

class QueueService
{
    private string $pendingDir;
    private string $processingDir;
    private string $doneDir;
    private string $failedDir;

    public function __construct(string $pendingDir, string $processingDir, string $doneDir, string $failedDir)
    {
        $this->pendingDir = $pendingDir;
        $this->processingDir = $processingDir;
        $this->doneDir = $doneDir;
        $this->failedDir = $failedDir;
    }

    public function listPending(int $limit): array
    {
        $files = array_slice(glob($this->pendingDir . '/*.json') ?: [], 0, $limit);
        $jobs = [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            $isValid = is_array($data);
            $jobs[] = new QueueJob($file, $isValid ? $data : [], $isValid);
        }

        return $jobs;
    }

    public function count(string $dir): int
    {
        return count(glob($dir . '/*.json') ?: []);
    }

    public function getPendingDir(): string
    {
        return $this->pendingDir;
    }

    public function getProcessingDir(): string
    {
        return $this->processingDir;
    }

    public function getDoneDir(): string
    {
        return $this->doneDir;
    }

    public function getFailedDir(): string
    {
        return $this->failedDir;
    }
}
