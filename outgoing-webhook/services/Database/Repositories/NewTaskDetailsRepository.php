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

    /**
     * Найти запись по request_id
     *
     * @param string $requestId ID запроса
     * @return array|null Данные записи или null
     */
    public function findByRequestId(string $requestId): ?array
    {
        $sql = "SELECT * FROM new_task_details WHERE request_id = :request_id LIMIT 1";
        $result = $this->database->queryOne($sql, [':request_id' => $requestId]);

        if ($result === null) {
            return null;
        }

        // Декодирование JSON полей
        if (isset($result['raw_payload'])) {
            $result['raw_payload_decoded'] = json_decode($result['raw_payload'], true) ?? [];
        }
        if (isset($result['extracted'])) {
            $result['extracted_decoded'] = json_decode($result['extracted'], true) ?? [];
        }

        return $result;
    }

    /**
     * Найти записи по task_id
     *
     * @param string $taskId ID задачи
     * @param int $limit Лимит записей
     * @return array Массив записей
     */
    public function findByTaskId(string $taskId, int $limit = 100): array
    {
        $sql = "SELECT * FROM new_task_details 
                WHERE task_id = :task_id 
                ORDER BY created_at DESC 
                LIMIT :limit";

        $results = $this->database->queryAll($sql, [
            ':task_id' => $taskId,
            ':limit' => $limit,
        ]);

        // Декодирование JSON полей
        foreach ($results as &$result) {
            if (isset($result['raw_payload'])) {
                $result['raw_payload_decoded'] = json_decode($result['raw_payload'], true) ?? [];
            }
            if (isset($result['extracted'])) {
                $result['extracted_decoded'] = json_decode($result['extracted'], true) ?? [];
            }
        }

        return $results;
    }

    /**
     * Найти записи по типу события
     *
     * @param string $eventType Тип события (например, ONTASKADD)
     * @param int $limit Лимит записей
     * @return array Массив записей
     */
    public function findByEventType(string $eventType, int $limit = 100): array
    {
        $sql = "SELECT * FROM new_task_details 
                WHERE event_type = :event_type 
                ORDER BY created_at DESC 
                LIMIT :limit";

        $results = $this->database->queryAll($sql, [
            ':event_type' => $eventType,
            ':limit' => $limit,
        ]);

        // Декодирование JSON полей
        foreach ($results as &$result) {
            if (isset($result['raw_payload'])) {
                $result['raw_payload_decoded'] = json_decode($result['raw_payload'], true) ?? [];
            }
            if (isset($result['extracted'])) {
                $result['extracted_decoded'] = json_decode($result['extracted'], true) ?? [];
            }
        }

        return $results;
    }

    /**
     * Найти записи по условию в extracted JSON
     * 
     * Примеры использования:
     * - findByExtractedField('responsibleId', '123') - найти задачи с ответственным ID = 123
     * - findByExtractedField('title', '%важн%', 'LIKE') - найти задачи с заголовком содержащим "важн"
     * - findByExtractedField('status', '5') - найти задачи со статусом 5
     *
     * @param string $field Поле в extracted JSON (например, 'title', 'responsibleId', 'status')
     * @param mixed $value Значение для поиска
     * @param string $operator Оператор сравнения (=, LIKE, !=, >, <, >=, <=)
     * @param int $limit Лимит записей
     * @return array Массив записей
     */
    public function findByExtractedField(string $field, $value, string $operator = '=', int $limit = 100): array
    {
        // SQLite JSON функции: json_extract для извлечения значения из JSON
        // Путь к полю в extracted: $.field_name
        $jsonPath = '$.' . $field;
        
        // Валидация оператора
        $allowedOperators = ['=', '!=', '>', '<', '>=', '<=', 'LIKE'];
        if (!in_array($operator, $allowedOperators, true)) {
            $operator = '=';
        }

        $sql = "SELECT * FROM new_task_details 
                WHERE json_extract(extracted, :json_path) {$operator} :value
                ORDER BY created_at DESC 
                LIMIT :limit";

        $params = [
            ':json_path' => $jsonPath,
            ':value' => $value,
            ':limit' => $limit,
        ];

        $results = $this->database->queryAll($sql, $params);

        // Декодирование JSON полей
        foreach ($results as &$result) {
            if (isset($result['raw_payload'])) {
                $result['raw_payload_decoded'] = json_decode($result['raw_payload'], true) ?? [];
            }
            if (isset($result['extracted'])) {
                $result['extracted_decoded'] = json_decode($result['extracted'], true) ?? [];
            }
        }

        return $results;
    }

    /**
     * Получить значение поля из extracted по условию
     * 
     * Пример: получить title задачи с task_id = '123'
     *
     * @param string $taskId ID задачи
     * @param string $field Поле в extracted JSON
     * @return mixed Значение поля или null
     */
    public function getExtractedFieldValue(string $taskId, string $field)
    {
        $sql = "SELECT json_extract(extracted, :json_path) as field_value 
                FROM new_task_details 
                WHERE task_id = :task_id 
                ORDER BY created_at DESC 
                LIMIT 1";

        $jsonPath = '$.' . $field;
        $result = $this->database->queryOne($sql, [
            ':json_path' => $jsonPath,
            ':task_id' => $taskId,
        ]);

        return $result['field_value'] ?? null;
    }

    /**
     * Получить значение поля из raw_payload по условию
     * 
     * Пример: получить значение из сырого ответа REST API
     *
     * @param string $taskId ID задачи
     * @param string $jsonPath JSONPath путь к полю (например, '$.result.task.TITLE')
     * @return mixed Значение поля или null
     */
    public function getRawPayloadFieldValue(string $taskId, string $jsonPath)
    {
        $sql = "SELECT json_extract(raw_payload, :json_path) as field_value 
                FROM new_task_details 
                WHERE task_id = :task_id 
                ORDER BY created_at DESC 
                LIMIT 1";

        $result = $this->database->queryOne($sql, [
            ':json_path' => $jsonPath,
            ':task_id' => $taskId,
        ]);

        return $result['field_value'] ?? null;
    }
}
