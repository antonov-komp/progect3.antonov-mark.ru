<?php
declare(strict_types=1);

/**
 * Репозиторий для записи обогащённых данных в БД
 */
class EnrichedDataRepository
{
    private DatabaseService $database;
    private ErrorService $errors;

    public function __construct(DatabaseService $database, ErrorService $errors)
    {
        $this->database = $database;
        $this->errors = $errors;
    }

    /**
     * Создать запись обогащённых данных
     *
     * @param array $data request_id, event_type, entity_type, entity_id, enriched_at, source_method?, response_time_ms?, data (array или JSON)
     * @return int|null ID созданной записи или null при ошибке
     */
    public function create(array $data): ?int
    {
        $payload = $data['data'] ?? [];
        $dataJson = is_string($payload) ? $payload : json_encode($payload);

        $sql = "INSERT INTO enriched_data (
            request_id, event_type, entity_type, entity_id,
            enriched_at, source_method, response_time_ms, data, created_at
        ) VALUES (
            :request_id, :event_type, :entity_type, :entity_id,
            :enriched_at, :source_method, :response_time_ms, :data, :created_at
        )";

        $now = date('Y-m-d H:i:s');
        $params = [
            ':request_id' => $data['request_id'] ?? '',
            ':event_type' => $data['event_type'] ?? '',
            ':entity_type' => $data['entity_type'] ?? '',
            ':entity_id' => $data['entity_id'] ?? '',
            ':enriched_at' => $data['enriched_at'] ?? $now,
            ':source_method' => $data['source_method'] ?? null,
            ':response_time_ms' => isset($data['response_time_ms']) ? (int) $data['response_time_ms'] : null,
            ':data' => $dataJson,
            ':created_at' => $data['created_at'] ?? $now,
        ];

        try {
            $id = $this->database->insert($sql, $params);
            return $id !== false ? $id : null;
        } catch (Throwable $e) {
            $this->errors->log('Failed to create enriched_data record', [
                'request_id' => $data['request_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
