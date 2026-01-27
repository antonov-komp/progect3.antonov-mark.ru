<?php
declare(strict_types=1);

/**
 * Репозиторий деталей сделок (по событию: полный crm.deal.get)
 */
class DealDetailsRepository
{
    private DatabaseService $database;
    private ErrorService $errors;

    public function __construct(DatabaseService $database, ErrorService $errors)
    {
        $this->database = $database;
        $this->errors = $errors;
    }

    /**
     * Создать запись деталей сделки
     *
     * @param array $data requestId, eventType, dealId, details, detailsResolved, formattedDetails, createdAt, keyFields (опц.)
     * @return int|null
     */
    public function create(array $data): ?int
    {
        $k = $data['keyFields'] ?? [];
        $sql = "INSERT INTO deal_details (
            request_id, event_type, deal_id, details, details_resolved, formatted_details, created_at,
            stage_id, stage_title, category_id, category_title, assigned_by_id, assigned_by_name, modify_by_id, modify_by_name
        ) VALUES (
            :request_id, :event_type, :deal_id, :details, :details_resolved, :formatted_details, :created_at,
            :stage_id, :stage_title, :category_id, :category_title, :assigned_by_id, :assigned_by_name, :modify_by_id, :modify_by_name
        )";

        $resolved = $data['detailsResolved'] ?? null;
        $params = [
            ':request_id' => $data['requestId'] ?? null,
            ':event_type' => $data['eventType'] ?? null,
            ':deal_id' => $data['dealId'] ?? null,
            ':details' => json_encode($data['details'] ?? [], JSON_UNESCAPED_UNICODE),
            ':details_resolved' => is_array($resolved) ? json_encode($resolved, JSON_UNESCAPED_UNICODE) : null,
            ':formatted_details' => $data['formattedDetails'] ?? null,
            ':created_at' => $data['createdAt'] ?? date('Y-m-d H:i:s'),
            ':stage_id' => $k['stage_id'] ?? null,
            ':stage_title' => $k['stage_title'] ?? null,
            ':category_id' => $k['category_id'] ?? null,
            ':category_title' => $k['category_title'] ?? null,
            ':assigned_by_id' => $k['assigned_by_id'] ?? null,
            ':assigned_by_name' => $k['assigned_by_name'] ?? null,
            ':modify_by_id' => $k['modify_by_id'] ?? null,
            ':modify_by_name' => $k['modify_by_name'] ?? null,
        ];

        try {
            $id = $this->database->insert($sql, $params);
            return $id !== false ? $id : null;
        } catch (Throwable $e) {
            $this->errors->log('Failed to create deal details', [
                'request_id' => $data['requestId'] ?? null,
                'deal_id' => $data['dealId'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Найти детали по request_id
     */
    public function findByRequestId(string $requestId): ?array
    {
        $sql = "SELECT * FROM deal_details WHERE request_id = :request_id LIMIT 1";
        $row = $this->database->queryOne($sql, [':request_id' => $requestId]);
        if ($row === null) {
            return null;
        }
        if (isset($row['details'])) {
            $row['details'] = json_decode($row['details'], true) ?? [];
        }
        if (isset($row['details_resolved']) && is_string($row['details_resolved'])) {
            $row['details_resolved'] = json_decode($row['details_resolved'], true) ?? [];
        }
        return $row;
    }

    /**
     * Найти записи по deal_id
     */
    public function findByDealId(string $dealId, int $limit = 100): array
    {
        $sql = "SELECT * FROM deal_details WHERE deal_id = :deal_id ORDER BY created_at DESC LIMIT :limit";
        $rows = $this->database->queryAll($sql, [':deal_id' => $dealId, ':limit' => $limit]);
        foreach ($rows as &$r) {
            if (isset($r['details'])) {
                $r['details'] = json_decode($r['details'], true) ?? [];
            }
            if (isset($r['details_resolved']) && is_string($r['details_resolved'])) {
                $r['details_resolved'] = json_decode($r['details_resolved'], true) ?? [];
            }
        }
        return $rows;
    }
}
