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
     * имя файла (file_name), размер (file_size), автор (author_id, author_name), текст комментария (comment_text).
     *
     * @param array $data request_id, task_id, ..., author_id, author_name, comment_text, ...
     * @return int|null ID созданной записи или null при ошибке
     */
    public function create(array $data): ?int
    {
        $sql = "INSERT INTO activity_first_metrics (
            request_id, task_id, logged_at, sync, duration_ms, success,
            files_count, deals_count, rate_limit_hit, error,
            activity_type, deal_ids, result_full, file_name, file_size,
            author_id, author_name, comment_text, created_at
        ) VALUES (
            :request_id, :task_id, :logged_at, :sync, :duration_ms, :success,
            :files_count, :deals_count, :rate_limit_hit, :error,
            :activity_type, :deal_ids, :result_full, :file_name, :file_size,
            :author_id, :author_name, :comment_text, :created_at
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
            ':author_id' => $data['author_id'] ?? '',
            ':author_name' => $data['author_name'] ?? '',
            ':comment_text' => $data['comment_text'] ?? '',
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

    /**
     * Проверить наличие записи по паре (request_id, task_id) — маркер «Activity First уже обработан»
     *
     * @param string $requestId ID запроса
     * @param string $taskId ID задачи
     * @return bool
     */
    public function existsByRequestAndTask(string $requestId, string $taskId): bool
    {
        $sql = "SELECT 1 FROM activity_first_metrics 
                WHERE request_id = :request_id AND task_id = :task_id LIMIT 1";
        $result = $this->database->queryOne($sql, [
            ':request_id' => $requestId,
            ':task_id' => $taskId,
        ]);
        return $result !== null;
    }
}
