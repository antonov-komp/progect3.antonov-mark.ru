<?php
declare(strict_types=1);

/**
 * Запись деталей комментария в файлы
 * 
 * Ответственность:
 * - Запись деталей комментария в файлы
 * - Запись обогащенных данных
 * - Проверка необходимости записи
 */
class CommentWriter
{
    private FilesystemService $filesystem;
    private ConfigService $config;
    private RequestService $request;
    private CommentFormatter $formatter;
    private string $basePath;

    public function __construct(
        FilesystemService $filesystem,
        ConfigService $config,
        RequestService $request,
        CommentFormatter $formatter
    ) {
        $this->filesystem = $filesystem;
        $this->config = $config;
        $this->request = $request;
        $this->formatter = $formatter;
        $this->basePath = dirname(__DIR__, 3);
    }

    /**
     * Запись деталей комментария в файл
     */
    public function writeDetailsRu(string $eventType, array $details): void
    {
        if (!str_starts_with($eventType, 'ONTASK')) {
            return;
        }

        $eventDir = $this->basePath . '/logs/' . $eventType;
        $this->filesystem->ensureDir($eventDir);
        
        $formatted = $this->formatter->formatDetailsRu($details);
        
        $this->filesystem->appendLine($eventDir . '/comment-details.log', $formatted);
    }

    /**
     * Запись обогащенных данных
     */
    public function writeEnriched(
        string $eventType,
        string $entityId,
        array $taskData,
        array $commentData,
        string $sourceMethod,
        string $rawPath,
        ?string $requestId
    ): void {
        $eventDir = $this->basePath . '/logs/' . $eventType;
        $this->filesystem->ensureDir($eventDir);

        $enriched = [
            'eventType' => $eventType,
            'entityType' => 'task',
            'entityId' => $entityId,
            'enrichedAt' => $this->request->now(),
            'requestId' => $requestId ?? 'unknown',
            'source' => [
                'method' => $sourceMethod,
            ],
            'rawRef' => $rawPath,
            'data' => [
                'task' => $taskData,
                'comment' => $commentData,
            ],
        ];

        $this->filesystem->writeJson($eventDir . '/enriched.json', $enriched);
    }

    /**
     * Проверка необходимости записи обогащенных данных
     */
    public function shouldWriteEnriched(?string $taskId): bool
    {
        if ($taskId === null || $taskId === '') {
            return false;
        }

        $setting = $this->config->get('OUTGOING_WEBHOOK_COMMENT_ENRICHED_TASK_ID');
        if ($setting === null) {
            return false;
        }

        if (is_string($setting)) {
            $items = array_filter(array_map('trim', explode(',', $setting)));
            return in_array($taskId, $items, true);
        }

        if (is_array($setting)) {
            $items = [];
            foreach ($setting as $value) {
                if (is_string($value)) {
                    $value = trim($value);
                    if ($value !== '') {
                        $items[] = $value;
                    }
                }
            }
            return in_array($taskId, $items, true);
        }

        return false;
    }
}
