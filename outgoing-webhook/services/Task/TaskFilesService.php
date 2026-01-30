<?php
declare(strict_types=1);

/**
 * Сервис работы с файлами в контексте задач (чат, комментарии).
 *
 * fileId из события комментария (скрепка/драг-н-дроп) — это ID файла на диске задачи.
 * Такой ID используется для присоединения к задаче (tasks.task.files.attach).
 * Для записи в сделку этот же ID не подставляется — см. DealFileService (другое хранилище).
 */
class TaskFilesService
{
    private ErrorService $errors;

    public function __construct(ErrorService $errors)
    {
        $this->errors = $errors;
    }

    public function getAttachedFiles(string $taskId, callable $restCall): array
    {
        $result = $restCall('task.item.getfiles', ['TASKID' => (int) $taskId]);
        if (!is_array($result) || !empty($result['error'])) {
            return [];
        }

        $files = $result['result'] ?? [];
        if (!is_array($files)) {
            return [];
        }

        $fileIds = [];
        foreach ($files as $file) {
            if (is_array($file) && isset($file['id'])) {
                $fileIds[] = (string) $file['id'];
            } elseif (is_scalar($file)) {
                $fileIds[] = (string) $file;
            }
        }

        return array_values(array_unique($fileIds));
    }

    public function attachFiles(string $taskId, array $fileIds, callable $restCall): array
    {
        $attached = [];
        $errors = [];
        $existing = $this->getAttachedFiles($taskId, $restCall);

        foreach ($fileIds as $fileId) {
            $fileId = (string) $fileId;
            if ($fileId === '' || in_array($fileId, $existing, true)) {
                continue;
            }
            $result = $restCall('tasks.task.files.attach', [
                'taskId' => (int) $taskId,
                'fileId' => (int) $fileId,
            ]);
            if (!is_array($result) || !empty($result['error'])) {
                $errorCode = $result['error'] ?? 'unknown';
                $errors[] = ['fileId' => $fileId, 'error' => $errorCode];
                $this->errors->log('Bitrix24 tasks.task.files.attach error', [
                    'taskId' => $taskId,
                    'fileId' => $fileId,
                    'error' => $errorCode,
                    'error_description' => $result['error_description'] ?? null,
                    'response' => $result,
                ]);
                continue;
            }
            $attached[] = $fileId;
        }

        return [
            'attached' => $attached,
            'errors' => $errors,
        ];
    }

    public function getDiskFileInfo(string $fileId, callable $restCall): ?array
    {
        $result = $restCall('disk.file.get', ['id' => (int) $fileId]);
        if (!is_array($result) || !empty($result['error'])) {
            return null;
        }

        $data = $result['result'] ?? null;
        return is_array($data) ? $data : null;
    }
}
