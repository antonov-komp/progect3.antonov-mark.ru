<?php
declare(strict_types=1);

/**
 * Обработчик событий задач
 * 
 * Ответственность:
 * - Обработка событий задач (ONTASKADD, ONTASKUPDATE)
 * - Получение деталей задачи через REST API
 * - Запись деталей задачи
 */
class TaskEventHandler
{
    private RestService $rest;
    private TaskDetailsService $taskDetails;
    private ErrorService $errors;

    public function __construct(
        RestService $rest,
        TaskDetailsService $taskDetails,
        ErrorService $errors
    ) {
        $this->rest = $rest;
        $this->taskDetails = $taskDetails;
        $this->errors = $errors;
    }

    public function handle(string $eventType, ?string $entityId, string $requestId): void
    {
        if ($entityId === null || !str_starts_with($eventType, 'ONTASK')) {
            return;
        }

        try {
            $result = $this->rest->call('tasks.task.get', ['id' => $entityId]);
            
            if (!empty($result['error'])) {
                $this->errors->log('Task details REST error', [
                    'requestId' => $requestId,
                    'taskId' => $entityId,
                    'error' => $result['error'],
                ]);
                return;
            }

            $taskPayload = $result['result'] ?? $result;
            if (!is_array($taskPayload)) {
                return;
            }

            $taskData = $taskPayload['task'] ?? $taskPayload;
            if (!is_array($taskData)) {
                return;
            }

            $details = $this->taskDetails->buildDetails(
                $taskData,
                $eventType,
                $requestId,
                $entityId
            );
            
            $this->taskDetails->writeDetailsRu($eventType, $details);
        } catch (Throwable $e) {
            $this->errors->log('Task details exception', [
                'requestId' => $requestId,
                'taskId' => $entityId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
