<?php
declare(strict_types=1);

/**
 * Репозиторий для хранения полного снимка новой задачи (ONTASKADD)
 */
class NewTaskDetailsRepository
{
    private DatabaseService $database;
    private ErrorService $errors;

    public function __construct(DatabaseService $database, ErrorService $errors)
    {
        $this->database = $database;
        $this->errors = $errors;
    }

    /**
     * Создаёт запись в new_task_details
     *
     * @param array{requestId:string,eventType:string,taskId:string,rawPayload:string,extracted:array,createdAt?:string} $data
     * @return int|null
     */
    public function create(array $data): ?int
    {
        $sql = "INSERT INTO new_task_details (
            request_id, event_type, task_id, raw_payload, extracted, created_at
        ) VALUES (
            :request_id, :event_type, :task_id, :raw_payload, :extracted, :created_at
        )";

        $params = [
            ':request_id' => $data['requestId'] ?? null,
            ':event_type' => $data['eventType'] ?? null,
            ':task_id' => $data['taskId'] ?? null,
            ':raw_payload' => $data['rawPayload'] ?? '',
            ':extracted' => json_encode($data['extracted'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => $data['createdAt'] ?? date('Y-m-d H:i:s'),
        ];

        try {
            $id = $this->database->insert($sql, $params);
            return $id !== false ? $id : null;
        } catch (Throwable $e) {
            $this->errors->log('Failed to create new_task_details', [
                'request_id' => $data['requestId'] ?? null,
                'task_id' => $data['taskId'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
