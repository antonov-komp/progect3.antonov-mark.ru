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
    private ?CommentDetailsRepository $commentDetailsRepository;
    private string $basePath;

    public function __construct(
        FilesystemService $filesystem,
        ConfigService $config,
        RequestService $request,
        CommentFormatter $formatter,
        ?CommentDetailsRepository $commentDetailsRepository = null
    ) {
        $this->filesystem = $filesystem;
        $this->config = $config;
        $this->request = $request;
        $this->formatter = $formatter;
        $this->commentDetailsRepository = $commentDetailsRepository;
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
        
        // Запись в файл (для обратной совместимости)
        $this->filesystem->appendLine($eventDir . '/comment-details.log', $formatted);

        // Запись в БД (если репозиторий доступен)
        if ($this->commentDetailsRepository !== null) {
            // Извлекаем данные из структуры details
            $taskId = $details['taskId'] ?? null;
            $commentId = $details['commentId'] ?? null;
            $requestId = $details['requestId'] ?? null;
            
            // Если не найдено напрямую, пробуем извлечь из вложенных структур
            if ($taskId === null) {
                $taskId = $details['task']['id'] ?? $details['task']['ID'] ?? null;
            }
            if ($commentId === null) {
                $commentId = $details['comment']['id'] ?? $details['comment']['ID'] ?? null;
            }
            
            if ($taskId !== null && $commentId !== null && $requestId !== null) {
                $detailsData = [
                    'requestId' => $requestId,
                    'eventType' => $eventType,
                    'taskId' => (string) $taskId,
                    'commentId' => (string) $commentId,
                    'details' => $details,
                    'formattedDetails' => $formatted,
                    'createdAt' => $this->request->now(),
                ];
                
                $id = $this->commentDetailsRepository->create($detailsData);
                if ($id === null) {
                    // Ошибка уже залогирована в репозитории
                }
            }
        }
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
