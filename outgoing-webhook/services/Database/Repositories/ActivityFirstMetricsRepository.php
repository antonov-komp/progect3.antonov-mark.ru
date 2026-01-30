<?php
declare(strict_types=1);

/**
 * Репозиторий для записи метрик ActivityFirst в БД
 *
 * Таблица activity_first_metrics связывает события (events.request_id)
 * с фактом обработки Activity (обложка, согласованный бланк и т.д.).
 */
class ActivityFirstMetricsRepository
{
    private DatabaseService $database;
    private ErrorService $errors;

    public function __construct(DatabaseService $database, ErrorService $errors)
    {
        $this->database = $database;
        $this->errors = $errors;
    }

    /**
     * Создать запись метрики Activity
     *
     * Поля: задача (task_id), сделки (deal_ids), тип (activity_type), полный результат (result_full),
     * имя файла с форматом (file_name), размер файла (file_size).
     * Успех проверяется фактом наличия файлов в задаче и в поле сделки (verified в result_full).
     *
     * @param array $data request_id, task_id, ..., activity_type, deal_ids, result_full, file_name, file_size
     * @return int|null ID созданной записи или null при ошибке
     */
    public function create(array $data): ?int
    {
        $sql = "INSERT INTO activity_first_metrics (
            request_id, task_id, logged_at, sync, duration_ms, success,
            files_count, deals_count, rate_limit_hit, error,
            activity_type, deal_ids, result_full, file_name, file_size, created_at
        ) VALUES (
            :request_id, :task_id, :logged_at, :sync, :duration_ms, :success,
            :files_count, :deals_count, :rate_limit_hit, :error,
            :activity_type, :deal_ids, :result_full, :file_name, :file_size, :created_at
        )";

        $params = [
            ':request_id' => $data['request_id'] ?? null,
            ':task_id' => $data['task_id'] ?? null,
            ':logged_at' => $data['logged_at'] ?? date('Y-m-d H:i:s'),
            ':sync' => isset($data['sync']) ? (int)(bool)$data['sync'] : 1,
            ':duration_ms' => $data['duration_ms'] ?? null,
            ':success' => isset($data['success']) ? (int)(bool)$data['success'] : 0,
            ':files_count' => (int)($data['files_count'] ?? 0),
            ':deals_count' => (int)($data['deals_count'] ?? 0),
            ':rate_limit_hit' => isset($data['rate_limit_hit']) ? (int)(bool)$data['rate_limit_hit'] : 0,
            ':error' => $data['error'] ?? null,
            ':activity_type' => $data['activity_type'] ?? '',
            ':deal_ids' => $data['deal_ids'] ?? '',
            ':result_full' => $data['result_full'] ?? null,
            ':file_name' => $data['file_name'] ?? '',
            ':file_size' => isset($data['file_size']) && is_numeric($data['file_size']) ? (int) $data['file_size'] : null,
            ':created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        try {
            $id = $this->database->insert($sql, $params);
            return $id !== false ? $id : null;
        } catch (Throwable $e) {
            $this->errors->log('Failed to create activity_first_metrics record', [
                'request_id' => $data['request_id'] ?? null,
                'task_id' => $data['task_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
