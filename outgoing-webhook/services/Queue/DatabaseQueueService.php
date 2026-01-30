<?php
declare(strict_types=1);

/**
 * Сервис очереди с использованием БД
 * 
 * Ответственность:
 * - Получение списка заданий со статусом pending
 * - Подсчет заданий по статусам
 * - Управление статусами заданий
 * 
 * Реализует интерфейс, совместимый с QueueService для обратной совместимости
 */
class DatabaseQueueService
{
    private QueueRepository $queueRepository;
    private ErrorService $errors;

    public function __construct(QueueRepository $queueRepository, ErrorService $errors)
    {
        $this->queueRepository = $queueRepository;
        $this->errors = $errors;
    }

    /**
     * Нормализация строки БД (snake_case) в формат QueueRunner (camelCase)
     */
    private function normalizeJobData(array $row): array
    {
        $map = [
            'request_id' => 'requestId',
            'event_type' => 'eventType',
            'entity_type' => 'entityType',
            'entity_id' => 'entityId',
            'created_at' => 'createdAt',
            'updated_at' => 'updatedAt',
            'processed_at' => 'processedAt',
            'error_message' => 'errorMessage',
            'token_source' => 'tokenSource',
            'event_handler_id' => 'eventHandlerId',
            'member_id' => 'memberId',
        ];
        $data = [];
        foreach ($row as $key => $value) {
            $camel = $map[$key] ?? $key;
            $data[$camel] = $value;
        }
        return $data;
    }

    /**
     * Получить список заданий со статусом pending
     * Данные приводятся к camelCase; rawPath = db://queue_jobs/{id}
     *
     * @param int $limit Лимит записей
     * @return array Массив QueueJob объектов
     */
    public function listPending(int $limit): array
    {
        $jobs = $this->queueRepository->listPending($limit);
        $queueJobs = [];
        foreach ($jobs as $row) {
            $data = $this->normalizeJobData($row);
            $jobId = (int) ($row['id'] ?? 0);
            $data['rawPath'] = 'db://queue_jobs/' . $jobId;
            $queueJobs[] = new QueueJob(
                $data['rawPath'],
                $data,
                true
            );
        }
        return $queueJobs;
    }

    /**
     * Подсчитать количество заданий.
     * Принимает либо статус (pending, processing, done, failed), либо виртуальный путь (db://queue/pending и т.д.)
     */
    public function count(string $statusOrDir): int
    {
        $status = $this->dirToStatus($statusOrDir);
        return $status !== null
            ? $this->queueRepository->countByStatus($status)
            : 0;
    }

    /**
     * Преобразование виртуального пути или имени статуса в статус для БД
     */
    private function dirToStatus(string $statusOrDir): ?string
    {
        $map = [
            'pending' => 'pending',
            'processing' => 'processing',
            'done' => 'done',
            'failed' => 'failed',
            'db://queue/pending' => 'pending',
            'db://queue/processing' => 'processing',
            'db://queue/done' => 'done',
            'db://queue/failed' => 'failed',
        ];
        return $map[$statusOrDir] ?? null;
    }

    /**
     * Получить количество заданий в pending
     * 
     * @return int
     */
    public function countPending(): int
    {
        return $this->count('pending');
    }

    /**
     * Получить количество заданий в processing
     * 
     * @return int
     */
    public function countProcessing(): int
    {
        return $this->count('processing');
    }

    /**
     * Получить количество заданий в done
     * 
     * @return int
     */
    public function countDone(): int
    {
        return $this->count('done');
    }

    /**
     * Получить количество заданий в failed
     * 
     * @return int
     */
    public function countFailed(): int
    {
        return $this->count('failed');
    }

    /**
     * Методы для обратной совместимости с QueueService
     * (используются в QueueRunner и других местах)
     */
    
    public function getPendingDir(): string
    {
        return 'db://queue/pending';
    }

    public function getProcessingDir(): string
    {
        return 'db://queue/processing';
    }

    public function getDoneDir(): string
    {
        return 'db://queue/done';
    }

    public function getFailedDir(): string
    {
        return 'db://queue/failed';
    }
}
