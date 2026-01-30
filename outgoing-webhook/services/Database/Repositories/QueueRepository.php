<?php
declare(strict_types=1);

/**
 * Репозиторий для работы с очередью в БД
 * 
 * Ответственность:
 * - CRUD операции для заданий очереди
 * - Управление статусами заданий
 * - Атомарные операции через транзакции
 */
class QueueRepository
{
    private DatabaseService $database;
    private ErrorService $errors;
    private ?EventRepository $eventRepository = null;

    public function __construct(DatabaseService $database, ErrorService $errors)
    {
        $this->database = $database;
        $this->errors = $errors;
    }

    /**
     * Создать задание в очереди
     * 
     * @param array $jobData Данные задания
     * @return int|null ID созданного задания или null при ошибке
     */
    public function createJob(array $jobData): ?int
    {
        // Проверка существования события перед созданием задания (для внешнего ключа)
        $requestId = $jobData['requestId'] ?? null;
        if ($requestId === null) {
            $this->errors->log('Cannot create queue job without request_id', $jobData);
            return null;
        }

        // Проверяем, существует ли событие с таким request_id
        // Если нет - создаем событие сначала (fallback для совместимости)
        if ($this->eventRepository === null) {
            $this->eventRepository = new EventRepository($this->database, $this->errors);
        }
        $existingEvent = $this->eventRepository->findByRequestId($requestId);
        
        if ($existingEvent === null) {
            // Событие не найдено - создаем его для внешнего ключа
            $minimalEvent = [
                'requestId' => $requestId,
                'eventType' => $jobData['eventType'] ?? 'UNKNOWN',
                'entityType' => $jobData['entityType'] ?? null,
                'entityId' => $jobData['entityId'] ?? null,
                'receivedAt' => $jobData['createdAt'] ?? date('Y-m-d H:i:s'),
                'ip' => null,
                'tokenSource' => $jobData['tokenSource'] ?? null,
                'eventHandlerId' => $jobData['eventHandlerId'] ?? null,
                'memberId' => $jobData['memberId'] ?? null,
                'payload' => $jobData['payload'] ?? [],
                'createdAt' => $jobData['createdAt'] ?? date('Y-m-d H:i:s'),
            ];
            
            $eventId = $this->eventRepository->create($minimalEvent);
            if ($eventId === null) {
                $this->errors->log('Failed to create event for queue job', [
                    'request_id' => $requestId,
                ]);
                // Продолжаем попытку создания задания - возможно внешний ключ отключен
            }
        }

        $sql = "INSERT INTO queue_jobs (
            request_id, event_type, entity_type, entity_id,
            status, attempt, priority, source, token_source,
            event_handler_id, member_id, payload, created_at, updated_at
        ) VALUES (
            :request_id, :event_type, :entity_type, :entity_id,
            :status, :attempt, :priority, :source, :token_source,
            :event_handler_id, :member_id, :payload, :created_at, :updated_at
        )";

        $now = date('Y-m-d H:i:s');
        $params = [
            ':request_id' => $requestId,
            ':event_type' => $jobData['eventType'] ?? null,
            ':entity_type' => $jobData['entityType'] ?? null,
            ':entity_id' => $jobData['entityId'] ?? null,
            ':status' => $jobData['status'] ?? 'pending',
            ':attempt' => $jobData['attempt'] ?? 0,
            ':priority' => $jobData['priority'] ?? 'normal',
            ':source' => $jobData['source'] ?? 'outgoing-webhook',
            ':token_source' => $jobData['tokenSource'] ?? null,
            ':event_handler_id' => $jobData['eventHandlerId'] ?? null,
            ':member_id' => $jobData['memberId'] ?? null,
            ':payload' => json_encode($jobData['payload'] ?? []),
            ':created_at' => $jobData['createdAt'] ?? $now,
            ':updated_at' => $now,
        ];

        try {
            $id = $this->database->insert($sql, $params);
            return $id !== false ? $id : null;
        } catch (Exception $e) {
            // Если ошибка внешнего ключа - событие не создано, создаем его сейчас
            if (strpos($e->getMessage(), 'FOREIGN KEY') !== false && $existingEvent === null) {
                // Ленивая инициализация EventRepository
                if ($this->eventRepository === null) {
                    $this->eventRepository = new EventRepository($this->database, $this->errors);
                }
                
                // Создаем минимальное событие для внешнего ключа
                $minimalEvent = [
                    'requestId' => $requestId,
                    'eventType' => $jobData['eventType'] ?? 'UNKNOWN',
                    'entityType' => $jobData['entityType'] ?? null,
                    'entityId' => $jobData['entityId'] ?? null,
                    'receivedAt' => $now,
                    'ip' => null,
                    'tokenSource' => $jobData['tokenSource'] ?? null,
                    'eventHandlerId' => $jobData['eventHandlerId'] ?? null,
                    'memberId' => $jobData['memberId'] ?? null,
                    'payload' => [],
                    'createdAt' => $now,
                ];
                
                $eventId = $this->eventRepository->create($minimalEvent);
                if ($eventId !== null) {
                    // Повторяем попытку создания задания
                    try {
                        $id = $this->database->insert($sql, $params);
                        return $id !== false ? $id : null;
                    } catch (Exception $e2) {
                        $this->errors->log('Failed to create queue job after creating event', [
                            'request_id' => $requestId,
                            'error' => $e2->getMessage(),
                        ]);
                        return null;
                    }
                }
            }
            
            $this->errors->log('Failed to create queue job', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Получить список заданий со статусом pending
     * 
     * @param int $limit Лимит записей
     * @return array Массив заданий
     */
    public function listPending(int $limit = 100): array
    {
        $sql = "SELECT * FROM queue_jobs 
                WHERE status = 'pending' 
                ORDER BY priority DESC, created_at ASC 
                LIMIT :limit";
        
        $results = $this->database->queryAll($sql, [':limit' => $limit]);

        // Декодирование JSON полей
        foreach ($results as &$result) {
            if (isset($result['payload'])) {
                $result['payload'] = json_decode($result['payload'], true) ?? [];
            }
        }

        return $results;
    }

    /**
     * Обновить статус задания
     * 
     * @param int $jobId ID задания
     * @param string $status Новый статус
     * @param string|null $errorMessage Сообщение об ошибке (если статус failed)
     * @return bool Успешность обновления
     */
    public function updateStatus(int $jobId, string $status, ?string $errorMessage = null): bool
    {
        $now = date('Y-m-d H:i:s');
        
        $sql = "UPDATE queue_jobs 
                SET status = :status, 
                    updated_at = :updated_at,
                    processed_at = :processed_at,
                    error_message = :error_message
                WHERE id = :id";

        $params = [
            ':id' => $jobId,
            ':status' => $status,
            ':updated_at' => $now,
            ':processed_at' => ($status === 'done' || $status === 'failed') ? $now : null,
            ':error_message' => $errorMessage,
        ];

        try {
            $this->database->query($sql, $params);
            return true;
        } catch (Exception $e) {
            $this->errors->log('Failed to update queue job status', [
                'job_id' => $jobId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Увеличить счетчик попыток
     * 
     * @param int $jobId ID задания
     * @return bool Успешность обновления
     */
    public function incrementAttempt(int $jobId): bool
    {
        $sql = "UPDATE queue_jobs 
                SET attempt = attempt + 1, 
                    updated_at = :updated_at
                WHERE id = :id";

        try {
            $this->database->query($sql, [
                ':id' => $jobId,
                ':updated_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (Exception $e) {
            $this->errors->log('Failed to increment attempt', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Подсчитать количество заданий по статусу
     * 
     * @param string $status Статус
     * @return int Количество заданий
     */
    public function countByStatus(string $status): int
    {
        $sql = "SELECT COUNT(*) as count FROM queue_jobs WHERE status = :status";
        $result = $this->database->queryOne($sql, [':status' => $status]);
        
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Взять задание в работу (pending → processing)
     * Обновляет только записи со статусом pending для избежания гонки при конкурентном запуске.
     *
     * @param int $jobId ID задания
     * @return bool true если статус обновлён
     */
    public function setProcessing(int $jobId): bool
    {
        $now = date('Y-m-d H:i:s');
        $sql = "UPDATE queue_jobs 
                SET status = 'processing', 
                    updated_at = :updated_at,
                    processed_at = NULL,
                    error_message = NULL
                WHERE id = :id AND status = 'pending'";

        try {
            $stmt = $this->database->query($sql, [
                ':id' => $jobId,
                ':updated_at' => $now,
            ]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->errors->log('Failed to set queue job processing', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Вернуть зависшие задания (processing) в pending
     *
     * @param int $timeoutSeconds Таймаут в секундах (например 900 = 15 минут)
     * @return int Количество обновлённых записей
     */
    public function resetStaleProcessing(int $timeoutSeconds): int
    {
        $now = date('Y-m-d H:i:s');
        // SQLite: сравнение времени через julianday или strftime
        $sql = "UPDATE queue_jobs 
                SET status = 'pending', updated_at = :updated_at 
                WHERE status = 'processing' 
                AND (julianday('now') - julianday(updated_at)) * 86400 > :timeout";

        try {
            $stmt = $this->database->query($sql, [
                ':updated_at' => $now,
                ':timeout' => $timeoutSeconds,
            ]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            $this->errors->log('Failed to reset stale processing jobs', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Найти задание по request_id
     * 
     * @param string $requestId ID запроса
     * @return array|null Данные задания или null
     */
    public function findByRequestId(string $requestId): ?array
    {
        $sql = "SELECT * FROM queue_jobs WHERE request_id = :request_id LIMIT 1";
        $result = $this->database->queryOne($sql, [':request_id' => $requestId]);
        
        if ($result === null) {
            return null;
        }

        // Декодирование JSON полей
        if (isset($result['payload'])) {
            $result['payload'] = json_decode($result['payload'], true) ?? [];
        }

        return $result;
    }
}
