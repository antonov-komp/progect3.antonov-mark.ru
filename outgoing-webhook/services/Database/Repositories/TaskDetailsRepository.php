<?php
declare(strict_types=1);

/**
 * Репозиторий для работы с деталями задач в БД
 * 
 * Ответственность:
 * - Сохранение деталей задач
 * - Чтение деталей задач
 * - Поиск по различным критериям
 */
class TaskDetailsRepository
{
    private DatabaseService $database;
    private ErrorService $errors;

    public function __construct(DatabaseService $database, ErrorService $errors)
    {
        $this->database = $database;
        $this->errors = $errors;
    }

    /**
     * Создать запись деталей задачи
     * 
     * @param array $detailsData Данные деталей
     * @return int|null ID созданной записи или null при ошибке
     */
    public function create(array $detailsData): ?int
    {
        $sql = "INSERT INTO task_details (
            request_id, event_type, task_id, details, formatted_details, created_at
        ) VALUES (
            :request_id, :event_type, :task_id, :details, :formatted_details, :created_at
        )";

        $params = [
            ':request_id' => $detailsData['requestId'] ?? null,
            ':event_type' => $detailsData['eventType'] ?? null,
            ':task_id' => $detailsData['taskId'] ?? null,
            ':details' => json_encode($detailsData['details'] ?? []),
            ':formatted_details' => $detailsData['formattedDetails'] ?? null,
            ':created_at' => $detailsData['createdAt'] ?? date('Y-m-d H:i:s'),
        ];

        try {
            $id = $this->database->insert($sql, $params);
            return $id !== false ? $id : null;
        } catch (Exception $e) {
            $this->errors->log('Failed to create task details', [
                'request_id' => $detailsData['requestId'] ?? null,
                'task_id' => $detailsData['taskId'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Найти детали задачи по request_id
     * 
     * @param string $requestId ID запроса
     * @return array|null Данные деталей или null
     */
    public function findByRequestId(string $requestId): ?array
    {
        $sql = "SELECT * FROM task_details WHERE request_id = :request_id LIMIT 1";
        $result = $this->database->queryOne($sql, [':request_id' => $requestId]);
        
        if ($result === null) {
            return null;
        }

        // Декодирование JSON полей
        if (isset($result['details'])) {
            $result['details'] = json_decode($result['details'], true) ?? [];
        }

        return $result;
    }

    /**
     * Найти детали задачи по task_id
     * 
     * @param string $taskId ID задачи
     * @param int $limit Лимит записей
     * @return array Массив деталей
     */
    public function findByTaskId(string $taskId, int $limit = 100): array
    {
        $sql = "SELECT * FROM task_details 
                WHERE task_id = :task_id 
                ORDER BY created_at DESC 
                LIMIT :limit";
        
        $results = $this->database->queryAll($sql, [
            ':task_id' => $taskId,
            ':limit' => $limit,
        ]);

        // Декодирование JSON полей
        foreach ($results as &$result) {
            if (isset($result['details'])) {
                $result['details'] = json_decode($result['details'], true) ?? [];
            }
        }

        return $results;
    }

    /**
     * Найти детали задачи по типу события
     * 
     * @param string $eventType Тип события
     * @param int $limit Лимит записей
     * @return array Массив деталей
     */
    public function findByEventType(string $eventType, int $limit = 100): array
    {
        $sql = "SELECT * FROM task_details 
                WHERE event_type = :event_type 
                ORDER BY created_at DESC 
                LIMIT :limit";
        
        $results = $this->database->queryAll($sql, [
            ':event_type' => $eventType,
            ':limit' => $limit,
        ]);

        // Декодирование JSON полей
        foreach ($results as &$result) {
            if (isset($result['details'])) {
                $result['details'] = json_decode($result['details'], true) ?? [];
            }
        }

        return $results;
    }
}
