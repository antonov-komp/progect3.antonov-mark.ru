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
     * Получить список заданий со статусом pending
     * 
     * @param int $limit Лимит записей
     * @return array Массив QueueJob объектов
     */
    public function listPending(int $limit): array
    {
        $jobs = $this->queueRepository->listPending($limit);
        
        // Преобразование в QueueJob объекты для обратной совместимости
        $queueJobs = [];
        foreach ($jobs as $jobData) {
            $queueJobs[] = new QueueJob(
                "db://queue_jobs/{$jobData['id']}", // Виртуальный путь для обратной совместимости
                $jobData,
                true // Всегда валидные данные из БД
            );
        }

        return $queueJobs;
    }

    /**
     * Подсчитать количество заданий по статусу
     * 
     * @param string $status Статус (pending, processing, done, failed)
     * @return int Количество заданий
     */
    public function count(string $status): int
    {
        return $this->queueRepository->countByStatus($status);
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
