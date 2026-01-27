<?php
declare(strict_types=1);

/**
 * Репозиторий для работы с состояниями сущностей в БД
 * 
 * Ответственность:
 * - Сохранение состояний сущностей
 * - Чтение состояний сущностей
 * - Обновление состояний сущностей
 */
class EntityStateRepository
{
    private DatabaseService $database;
    private ErrorService $errors;

    public function __construct(DatabaseService $database, ErrorService $errors)
    {
        $this->database = $database;
        $this->errors = $errors;
    }

    /**
     * Сохранить или обновить состояние сущности
     * 
     * @param string $entityType Тип сущности
     * @param string $entityId ID сущности
     * @param array $state Состояние (будет сериализовано в JSON)
     * @return bool Успешность операции
     */
    public function save(string $entityType, string $entityId, array $state): bool
    {
        $sql = "INSERT INTO entity_states (entity_type, entity_id, state, updated_at)
                VALUES (:entity_type, :entity_id, :state, :updated_at)
                ON CONFLICT(entity_type, entity_id) 
                DO UPDATE SET 
                    state = :state,
                    updated_at = :updated_at";

        $params = [
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':state' => json_encode($state),
            ':updated_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $this->database->query($sql, $params);
            return true;
        } catch (Exception $e) {
            $this->errors->log('Failed to save entity state', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Получить состояние сущности
     * 
     * @param string $entityType Тип сущности
     * @param string $entityId ID сущности
     * @return array|null Состояние или null если не найдено
     */
    public function get(string $entityType, string $entityId): ?array
    {
        $sql = "SELECT * FROM entity_states 
                WHERE entity_type = :entity_type AND entity_id = :entity_id 
                LIMIT 1";
        
        $result = $this->database->queryOne($sql, [
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
        ]);

        if ($result === null) {
            return null;
        }

        // Декодирование JSON полей
        if (isset($result['state'])) {
            $result['state'] = json_decode($result['state'], true) ?? [];
        }

        return $result;
    }

    /**
     * Удалить состояние сущности
     * 
     * @param string $entityType Тип сущности
     * @param string $entityId ID сущности
     * @return bool Успешность операции
     */
    public function delete(string $entityType, string $entityId): bool
    {
        $sql = "DELETE FROM entity_states 
                WHERE entity_type = :entity_type AND entity_id = :entity_id";

        try {
            $this->database->query($sql, [
                ':entity_type' => $entityType,
                ':entity_id' => $entityId,
            ]);
            return true;
        } catch (Exception $e) {
            $this->errors->log('Failed to delete entity state', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Получить все состояния по типу сущности
     * 
     * @param string $entityType Тип сущности
     * @return array Массив состояний
     */
    public function getAllByEntityType(string $entityType): array
    {
        $sql = "SELECT * FROM entity_states 
                WHERE entity_type = :entity_type 
                ORDER BY updated_at DESC";
        
        $results = $this->database->queryAll($sql, [':entity_type' => $entityType]);

        // Декодирование JSON полей
        foreach ($results as &$result) {
            if (isset($result['state'])) {
                $result['state'] = json_decode($result['state'], true) ?? [];
            }
        }

        return $results;
    }
}
