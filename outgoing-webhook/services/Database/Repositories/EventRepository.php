<?php
declare(strict_types=1);

/**
 * Репозиторий для работы с событиями в БД
 * 
 * Ответственность:
 * - CRUD операции для событий
 * - Поиск событий по различным критериям
 * - Использование prepared statements для безопасности
 */
class EventRepository
{
    private DatabaseService $database;
    private ErrorService $errors;

    public function __construct(DatabaseService $database, ErrorService $errors)
    {
        $this->database = $database;
        $this->errors = $errors;
    }

    /**
     * Создать событие
     * 
     * @param array $eventData Данные события
     * @return int|null ID созданного события или null при ошибке
     */
    public function create(array $eventData): ?int
    {
        $sql = "INSERT INTO events (
            request_id, event_type, entity_type, entity_id,
            received_at, ip, token_source, event_handler_id, member_id,
            payload, created_at
        ) VALUES (
            :request_id, :event_type, :entity_type, :entity_id,
            :received_at, :ip, :token_source, :event_handler_id, :member_id,
            :payload, :created_at
        )";

        $params = [
            ':request_id' => $eventData['requestId'] ?? null,
            ':event_type' => $eventData['eventType'] ?? null,
            ':entity_type' => $eventData['entityType'] ?? null,
            ':entity_id' => $eventData['entityId'] ?? null,
            ':received_at' => $eventData['receivedAt'] ?? null,
            ':ip' => $eventData['ip'] ?? null,
            ':token_source' => $eventData['tokenSource'] ?? null,
            ':event_handler_id' => $eventData['eventHandlerId'] ?? null,
            ':member_id' => $eventData['memberId'] ?? null,
            ':payload' => json_encode($eventData['payload'] ?? []),
            ':created_at' => $eventData['createdAt'] ?? date('Y-m-d H:i:s'),
        ];

        try {
            $id = $this->database->insert($sql, $params);
            return $id !== false ? $id : null;
        } catch (Exception $e) {
            $this->errors->log('Failed to create event', [
                'request_id' => $eventData['requestId'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Найти событие по request_id
     * 
     * @param string $requestId ID запроса
     * @return array|null Данные события или null
     */
    public function findByRequestId(string $requestId): ?array
    {
        $sql = "SELECT * FROM events WHERE request_id = :request_id LIMIT 1";
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

    /**
     * Найти события по типу события
     * 
     * @param string $eventType Тип события
     * @param int $limit Лимит записей
     * @return array Массив событий
     */
    public function findByEventType(string $eventType, int $limit = 100): array
    {
        $sql = "SELECT * FROM events 
                WHERE event_type = :event_type 
                ORDER BY received_at DESC 
                LIMIT :limit";
        
        $results = $this->database->queryAll($sql, [
            ':event_type' => $eventType,
            ':limit' => $limit,
        ]);

        // Декодирование JSON полей
        foreach ($results as &$result) {
            if (isset($result['payload'])) {
                $result['payload'] = json_decode($result['payload'], true) ?? [];
            }
        }

        return $results;
    }

    /**
     * Найти события по entity_id и entity_type
     * 
     * @param string $entityId ID сущности
     * @param string $entityType Тип сущности
     * @param int $limit Лимит записей
     * @return array Массив событий
     */
    public function findByEntityId(string $entityId, string $entityType, int $limit = 100): array
    {
        $sql = "SELECT * FROM events 
                WHERE entity_id = :entity_id AND entity_type = :entity_type 
                ORDER BY received_at DESC 
                LIMIT :limit";
        
        $results = $this->database->queryAll($sql, [
            ':entity_id' => $entityId,
            ':entity_type' => $entityType,
            ':limit' => $limit,
        ]);

        // Декодирование JSON полей
        foreach ($results as &$result) {
            if (isset($result['payload'])) {
                $result['payload'] = json_decode($result['payload'], true) ?? [];
            }
        }

        return $results;
    }

    /**
     * Подсчитать количество событий по типу
     * 
     * @param string $eventType Тип события
     * @return int Количество событий
     */
    public function countByEventType(string $eventType): int
    {
        $sql = "SELECT COUNT(*) as count FROM events WHERE event_type = :event_type";
        $result = $this->database->queryOne($sql, [':event_type' => $eventType]);
        
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Получить последние события
     * 
     * @param int $limit Лимит записей
     * @return array Массив событий
     */
    public function getLatest(int $limit = 100): array
    {
        $sql = "SELECT * FROM events 
                ORDER BY received_at DESC 
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
}
