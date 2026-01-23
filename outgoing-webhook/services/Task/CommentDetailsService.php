<?php
declare(strict_types=1);

class CommentDetailsService
{
    private ?RestService $rest;
    private ErrorService $errors;
    private TaskDetailsService $taskDetails;
    private TaskFilesService $taskFiles;
    private DealFileService $dealFiles;
    private EntityIdentityService $identity;
    private RequestService $request;
    private LogValueFormatter $formatter;
    private FilesystemService $filesystem;
    private ConfigService $config;
    private string $basePath;

    public function __construct(
        ?RestService $rest,
        ErrorService $errors,
        TaskDetailsService $taskDetails,
        TaskFilesService $taskFiles,
        DealFileService $dealFiles,
        EntityIdentityService $identity,
        RequestService $request,
        LogValueFormatter $formatter,
        FilesystemService $filesystem,
        ConfigService $config
    ) {
        $this->rest = $rest;
        $this->errors = $errors;
        $this->taskDetails = $taskDetails;
        $this->taskFiles = $taskFiles;
        $this->dealFiles = $dealFiles;
        $this->identity = $identity;
        $this->request = $request;
        $this->formatter = $formatter;
        $this->filesystem = $filesystem;
        $this->config = $config;
        $this->basePath = dirname(__DIR__, 2);
    }

    public function handleCommentAdd(
        string $eventType,
        array $raw,
        array $job,
        ?string $entityId,
        string $rawPath,
        ?array $taskData
    ): void {
        if ($this->rest === null) {
            return;
        }

        $commentId = $this->identity->extractCommentId($raw['payload'] ?? []);
        $messageId = $this->identity->extractMessageId($raw['payload'] ?? []);
        if ($commentId === null || $entityId === null) {
            return;
        }

        $commentWritten = false;
        $commentData = null;
        $commentSource = null;
        $commentDetails = null;
        $restCall = fn(string $method, array $params = []) => $this->rest->call($method, $params);

        $fetch = $this->fetchDetails($restCall, $entityId, $commentId);
        if (is_array($fetch['data'])) {
            if (is_array($taskData)) {
                $taskData = $this->taskDetails->ensureCrmLinks($taskData, $entityId, $restCall);
            }
            $commentData = $fetch['data'];
            $commentSource = $fetch['method'];
            $commentDetails = $this->buildDetails(
                $fetch['data'],
                $eventType,
                $job['requestId'] ?? null,
                $entityId,
                $commentId,
                $fetch['method'],
                is_array($taskData) ? $taskData : null
            );
            $this->writeDetailsRu($eventType, $commentDetails);
            $commentWritten = true;
        } else {
            if (is_array($taskData)) {
                $taskData = $this->taskDetails->ensureCrmLinks($taskData, $entityId, $restCall);
                $chatId = $this->extractChatId($taskData);
                if ($chatId !== null && $messageId !== null) {
                    $chatFetch = $this->fetchChatMessageDetails($restCall, $chatId, $messageId);
                    if (is_array($chatFetch['data'])) {
                        $commentData = $chatFetch['data'];
                        $commentSource = $chatFetch['method'];
                        $commentDetails = $this->buildDetailsFromChat(
                            $chatFetch['data'],
                            $eventType,
                            $job['requestId'] ?? null,
                            $entityId,
                            $commentId,
                            $chatFetch['method'],
                            $taskData
                        );
                        $this->writeDetailsRu($eventType, $commentDetails);
                        $commentWritten = true;
                    }
                }
            }

            if (!$commentWritten) {
                $fallback = $this->buildFallback(
                    $eventType,
                    $job['requestId'] ?? null,
                    $entityId,
                    $commentId
                );
                $this->writeDetailsRu($eventType, $fallback);
                $this->errors->log('Comment details missing', [
                    'requestId' => $job['requestId'] ?? 'unknown',
                    'taskId' => $entityId,
                    'commentId' => $commentId,
                    'messageId' => $messageId,
                    'errors' => $fetch['errors'] ?? [],
                ]);
            }
        }

        if ($commentWritten && $this->shouldWriteEnriched($entityId) && is_array($commentData)) {
            if (is_array($taskData)) {
                $this->writeEnriched(
                    $eventType,
                    $entityId,
                    $taskData,
                    $commentData,
                    $commentSource ?? 'unknown',
                    $rawPath,
                    $job['requestId'] ?? null
                );
            }
        }

        if ($commentWritten && is_array($commentDetails) && !empty($commentDetails['activityFirst'])) {
            $result = $this->processActivityFirst($commentDetails, $entityId, $restCall);
            $this->taskDetails->logActivityFirst([
                'loggedAt' => $this->request->now(),
                'requestId' => $job['requestId'] ?? 'unknown',
                'taskId' => $entityId,
                'dealIds' => $result['dealIds'],
                'fileIds' => $result['fileIds'],
                'taskAttach' => $result['taskAttach'],
                'dealUpdates' => $result['dealUpdates'],
            ]);
        }
    }

    public function buildDetails(
        array $commentData,
        string $eventType,
        ?string $requestId,
        ?string $taskId,
        ?string $commentId,
        ?string $sourceMethod,
        ?array $taskData = null
    ): array {
        $meta = $this->taskDetails->extractMeta($taskData);
        $resolvedCommentId = $this->request->getFirstValue(
            $commentData,
            ['ID', 'id', 'COMMENT_ID', 'MESSAGE_ID', 'messageId']
        );

        $details = [
            'loggedAt' => $this->request->now(),
            'requestId' => $requestId ?? 'unknown',
            'eventType' => $eventType,
            'taskId' => $taskId ?? 'unknown',
            'commentId' => $commentId ?? ($resolvedCommentId ?? 'unknown'),
            'authorId' => $this->request->getFirstValue(
                $commentData,
                ['AUTHOR_ID', 'CREATED_BY', 'authorId', 'createdBy', ['author', 'id']]
            ) ?? 'unknown',
            'message' => $this->request->getFirstValue(
                $commentData,
                ['POST_MESSAGE', 'MESSAGE', 'TEXT', 'text', 'postMessage', 'content', 'message']
            ) ?? 'unknown',
            'createdAt' => $this->request->getFirstValue(
                $commentData,
                ['POST_DATE', 'CREATED_DATE', 'createdDate', 'dateCreate', 'date']
            ) ?? 'unknown',
            'sourceMethod' => $sourceMethod ?? 'unknown',
            'projectId' => $meta['projectId'],
            'projectName' => $meta['projectName'],
            'crmLinks' => $meta['crmLinks'],
        ];

        $details['commentKind'] = $this->resolveKind($details['authorId'] ?? null);
        $details['activityFirst'] = $this->taskDetails->evaluateActivityFirst($details);
        return $details;
    }

    public function buildFallback(
        string $eventType,
        ?string $requestId,
        ?string $taskId,
        ?string $commentId
    ): array {
        return [
            'loggedAt' => $this->request->now(),
            'requestId' => $requestId ?? 'unknown',
            'eventType' => $eventType,
            'taskId' => $taskId ?? 'unknown',
            'commentId' => $commentId ?? 'unknown',
            'authorId' => 'unknown',
            'commentKind' => 'unknown',
            'message' => 'контекст не доступен в REST',
            'createdAt' => 'unknown',
            'sourceMethod' => 'fallback',
        ];
    }

    public function resolveKind($authorId): string
    {
        if ($authorId === null) {
            return 'unknown';
        }

        $value = trim((string) $authorId);
        if ($value === '' || $value === 'unknown') {
            return 'unknown';
        }

        return $value === '0' ? 'system' : 'user';
    }

    public function formatDetailsRu(array $details): string
    {
        $files = $details['fileIds'] ?? [];
        $filesText = is_array($files) && !empty($files)
            ? implode(',', $files)
            : 'нет';
        $crmLinks = $details['crmLinks'] ?? [];
        $crmText = is_array($crmLinks) && !empty($crmLinks)
            ? implode(',', $crmLinks)
            : 'нет';

        $activityFirst = !empty($details['activityFirst']) ? 'да' : 'нет';

        return sprintf(
            'Дата=%s | requestId=%s | Событие=%s | Задача=%s | Проект=%s (%s) | CRM=%s | КомментарийID=%s | Автор=%s | Тип=%s | Создано=%s | Текст=%s | Файлы=%s | ActivityFirst=%s | Метод=%s',
            $details['loggedAt'] ?? 'unknown',
            $details['requestId'] ?? 'unknown',
            $details['eventType'] ?? 'unknown',
            $details['taskId'] ?? 'unknown',
            $this->formatter->normalize($details['projectName'] ?? 'unknown'),
            $details['projectId'] ?? 'unknown',
            $crmText,
            $details['commentId'] ?? 'unknown',
            $details['authorId'] ?? 'unknown',
            $details['commentKind'] ?? 'unknown',
            $details['createdAt'] ?? 'unknown',
            $this->formatter->normalize($details['message'] ?? 'unknown'),
            $filesText,
            $activityFirst,
            $details['sourceMethod'] ?? 'unknown'
        );
    }

    public function writeDetailsRu(string $eventType, array $details): void
    {
        if (!str_starts_with($eventType, 'ONTASK')) {
            return;
        }

        $eventDir = $this->basePath . '/logs/' . $eventType;
        $this->filesystem->ensureDir($eventDir);
        $this->filesystem->appendLine($eventDir . '/comment-details.log', $this->formatDetailsRu($details));
    }

    public function findCommentItem($payload, string $commentId): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $listKeys = ['items', 'comments', 'result'];
        foreach ($listKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $items = $payload[$key];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $itemId = $this->request->getFirstValue($item, ['ID', 'id', 'COMMENT_ID', 'MESSAGE_ID', 'messageId']);
                    if ($itemId !== null && (string) $itemId === (string) $commentId) {
                        return $item;
                    }
                }
                return null;
            }
        }

        if (array_keys($payload) === range(0, count($payload) - 1)) {
            foreach ($payload as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemId = $this->request->getFirstValue($item, ['ID', 'id', 'COMMENT_ID', 'MESSAGE_ID', 'messageId']);
                if ($itemId !== null && (string) $itemId === (string) $commentId) {
                    return $item;
                }
            }
        }

        return null;
    }

    public function fetchDetails(callable $restCall, string $taskId, string $commentId): array
    {
        $recentFrom = date('c', time() - 600);
        $attempts = [
            ['method' => 'task.commentitem.get', 'params' => ['taskId' => (int) $taskId, 'itemId' => (int) $commentId]],
            ['method' => 'task.commentitem.getlist', 'params' => [
                'taskId' => (int) $taskId,
                'ORDER' => ['ID' => 'DESC'],
                'FILTER' => ['ID' => (int) $commentId],
            ]],
            ['method' => 'task.commentitem.getlist', 'params' => [
                'taskId' => (int) $taskId,
                'ORDER' => ['ID' => 'DESC'],
                'FILTER' => [],
            ]],
            ['method' => 'task.commentitem.getlist', 'params' => [
                'taskId' => (int) $taskId,
                'ORDER' => ['POST_DATE' => 'DESC'],
                'FILTER' => ['>=POST_DATE' => $recentFrom],
            ]],
            ['method' => 'task.commentitem.getlist', 'params' => [
                'taskId' => (int) $taskId,
                'arOrder' => ['POST_DATE' => 'DESC'],
                'arFilter' => ['>=POST_DATE' => $recentFrom],
            ]],
        ];

        $errors = [];
        foreach ($attempts as $attempt) {
            $result = $restCall($attempt['method'], $attempt['params']);
            if (isset($result['error']) && $result['error'] !== '' && $result['error'] !== '0') {
                $errors[] = [
                    'method' => $attempt['method'],
                    'error' => $result['error'],
                    'info' => $result['error_information'] ?? null,
                ];
                continue;
            }

            $payloadData = $result['result'] ?? $result;
            if (!is_array($payloadData)) {
                $errors[] = ['method' => $attempt['method'], 'error' => 'invalid_payload'];
                continue;
            }

            $commentData = $payloadData['comment'] ?? $payloadData['item'] ?? null;
            if (is_array($commentData)) {
                return ['data' => $commentData, 'method' => $attempt['method'], 'errors' => $errors];
            }

            $found = $this->findCommentItem($payloadData, $commentId);
            if (is_array($found)) {
                return ['data' => $found, 'method' => $attempt['method'], 'errors' => $errors];
            }

            if (isset($payloadData['result']) && is_array($payloadData['result']) && $payloadData['result'] === []) {
                $errors[] = [
                    'method' => $attempt['method'],
                    'error' => 'empty_comment_list',
                ];
            }
        }

        return ['data' => null, 'method' => null, 'errors' => $errors];
    }

    public function extractChatId(array $taskData): ?string
    {
        $value = $this->request->getFirstValue($taskData, ['chatId', 'CHAT_ID', 'chat_id']);
        return $value !== null ? (string) $value : null;
    }

    public function findChatMessage(array $payload, string $messageId): ?array
    {
        $listKeys = ['messages', 'list', 'items', 'result'];
        foreach ($listKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach ($payload[$key] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $itemId = $this->request->getFirstValue($item, ['id', 'ID', 'messageId', 'MESSAGE_ID']);
                    if ($itemId !== null && (string) $itemId === (string) $messageId) {
                        return $item;
                    }
                }
            }
        }

        return null;
    }

    public function fetchChatMessageDetails(callable $restCall, string $chatId, string $messageId): array
    {
        $dialogId = 'chat' . $chatId;
        $attempts = [
            ['method' => 'im.dialog.messages.get', 'params' => ['DIALOG_ID' => $dialogId, 'LIMIT' => 50]],
            ['method' => 'im.dialog.messages.get', 'params' => ['dialog_id' => $dialogId, 'limit' => 50]],
        ];

        $errors = [];
        foreach ($attempts as $attempt) {
            $result = $restCall($attempt['method'], $attempt['params']);
            if (isset($result['error']) && $result['error'] !== '' && $result['error'] !== '0') {
                $errors[] = [
                    'method' => $attempt['method'],
                    'error' => $result['error'],
                    'info' => $result['error_information'] ?? null,
                ];
                continue;
            }

            $payloadData = $result['result'] ?? $result;
            if (!is_array($payloadData)) {
                $errors[] = ['method' => $attempt['method'], 'error' => 'invalid_payload'];
                continue;
            }

            $message = $this->findChatMessage($payloadData, $messageId);
            if (is_array($message)) {
                return ['data' => $message, 'method' => $attempt['method'], 'errors' => $errors];
            }
        }

        return ['data' => null, 'method' => null, 'errors' => $errors];
    }

    public function buildDetailsFromChat(
        array $messageData,
        string $eventType,
        ?string $requestId,
        ?string $taskId,
        ?string $commentId,
        ?string $sourceMethod,
        ?array $taskData = null
    ): array {
        $meta = $this->taskDetails->extractMeta($taskData);
        $fileIds = [];
        if (isset($messageData['params']) && is_array($messageData['params'])) {
            $params = $messageData['params'];
            if (isset($params['FILE_ID']) && is_array($params['FILE_ID'])) {
                foreach ($params['FILE_ID'] as $fileId) {
                    if ($fileId !== null && $fileId !== '') {
                        $fileIds[] = (string) $fileId;
                    }
                }
            }
            if (isset($params['ATTACH']) && is_array($params['ATTACH'])) {
                foreach ($params['ATTACH'] as $attach) {
                    if (is_array($attach) && isset($attach['ID'])) {
                        $fileIds[] = (string) $attach['ID'];
                    }
                }
            }
        }
        $fileIds = array_values(array_unique($fileIds));

        $details = [
            'loggedAt' => $this->request->now(),
            'requestId' => $requestId ?? 'unknown',
            'eventType' => $eventType,
            'taskId' => $taskId ?? 'unknown',
            'commentId' => $commentId ?? 'unknown',
            'authorId' => $this->request->getFirstValue($messageData, ['AUTHOR_ID', 'author_id', 'authorId', 'FROM_ID', 'from_id']) ?? 'unknown',
            'message' => $this->request->getFirstValue($messageData, ['TEXT', 'text', 'MESSAGE', 'message']) ?? 'unknown',
            'createdAt' => $this->request->getFirstValue($messageData, ['DATE_CREATE', 'date_create', 'DATE', 'date']) ?? 'unknown',
            'sourceMethod' => $sourceMethod ?? 'unknown',
            'fileIds' => $fileIds,
            'projectId' => $meta['projectId'],
            'projectName' => $meta['projectName'],
            'crmLinks' => $meta['crmLinks'],
        ];

        $details['commentKind'] = $this->resolveKind($details['authorId'] ?? null);
        $details['activityFirst'] = $this->taskDetails->evaluateActivityFirst($details);
        return $details;
    }

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

    /**
     * Обработка ActivityFirst (синхронная или асинхронная)
     * 
     * @param array $commentDetails Детали комментария с ActivityFirst=да
     * @param string $entityId ID задачи
     * @param callable $restCall Функция для REST API вызовов
     * @return array Результат обработки для логирования
     * @throws InvalidArgumentException При отсутствии необходимых данных
     */
    public function processActivityFirst(
        array $commentDetails,
        string $entityId,
        callable $restCall
    ): array {
        // Валидация входных данных
        if (empty($entityId) || !is_string($entityId)) {
            throw new InvalidArgumentException('Invalid taskId: ' . ($entityId ?? 'null'));
        }

        $fileIds = $commentDetails['fileIds'] ?? [];
        $fileIds = is_array($fileIds) ? array_values(array_unique($fileIds)) : [];
        
        if (empty($fileIds)) {
            throw new InvalidArgumentException('FileIds are required for ActivityFirst processing');
        }

        $dealIds = $this->taskDetails->extractDealIds($commentDetails['crmLinks'] ?? []);
        
        if (empty($dealIds)) {
            throw new InvalidArgumentException('DealIds are required for ActivityFirst processing');
        }

        // Валидация каждого fileId
        foreach ($fileIds as $fileId) {
            if (!is_numeric($fileId) || (int)$fileId <= 0) {
                throw new InvalidArgumentException('Invalid fileId: ' . $fileId);
            }
        }

        // Валидация каждого dealId
        foreach ($dealIds as $dealId) {
            if (empty($dealId) || !is_string($dealId)) {
                throw new InvalidArgumentException('Invalid dealId: ' . ($dealId ?? 'null'));
            }
        }

        $taskAttach = ['attached' => [], 'errors' => []];
        if (!empty($fileIds)) {
            $taskAttach = $this->taskFiles->attachFiles($entityId, $fileIds, $restCall);
        }

        $dealUpdates = [];
        $fileDataList = [];
        foreach ($fileIds as $fileId) {
            $fileData = $this->dealFiles->buildDealFileData($fileId, $restCall);
            if ($fileData !== null) {
                $fileDataList[] = ['fileData' => $fileData];
            }
        }

        foreach ($dealIds as $dealId) {
            $dealUpdates[] = array_merge(
                ['dealId' => $dealId],
                $this->dealFiles->updateDealFiles($dealId, 'UF_CRM_1759233362672', $fileDataList, $restCall)
            );
        }

        return [
            'dealIds' => $dealIds,
            'fileIds' => $fileIds,
            'taskAttach' => $taskAttach,
            'dealUpdates' => $dealUpdates,
        ];
    }
}
