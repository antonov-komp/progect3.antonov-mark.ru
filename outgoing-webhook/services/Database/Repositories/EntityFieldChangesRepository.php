<?php
declare(strict_types=1);

/**
 * Репозиторий изменений полей сущностей (что было → что стало)
 */
class EntityFieldChangesRepository
{
    private DatabaseService $database;
    private ErrorService $errors;

    public function __construct(DatabaseService $database, ErrorService $errors)
    {
        $this->database = $database;
        $this->errors = $errors;
    }

    /**
     * Сохранить запись об изменениях полей
     *
     * @param string $entityType Тип сущности (например, 'deal')
     * @param string $entityId ID сущности
     * @param string $eventType Тип события (например, 'ONCRMDEALUPDATE')
     * @param string $changedAt ISO 8601
     * @param array $changes [ 'FIELD' => [ 'old' => ..., 'new' => ... ], ... ]
     * @param array|null $changesResolved [ 'FIELD' => [ 'title' => '...', 'old_display' => '...', 'new_display' => '...' ], ... ]
     * @return int|null ID записи или null при ошибке
     */
    public function create(
        string $entityType,
        string $entityId,
        string $eventType,
        string $changedAt,
        array $changes,
        ?array $changesResolved = null
    ): ?int {
        $sql = "INSERT INTO entity_field_changes (entity_type, entity_id, event_type, changed_at, changes, changes_resolved, created_at)
                VALUES (:entity_type, :entity_id, :event_type, :changed_at, :changes, :changes_resolved, :created_at)";

        $params = [
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':event_type' => $eventType,
            ':changed_at' => $changedAt,
            ':changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            ':changes_resolved' => $changesResolved !== null ? json_encode($changesResolved, JSON_UNESCAPED_UNICODE) : null,
            ':created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $this->database->query($sql, $params);
            $id = (int) $this->database->getConnection()->lastInsertId();
            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            $this->errors->log('Failed to save entity field changes', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
