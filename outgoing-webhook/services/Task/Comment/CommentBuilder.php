<?php
declare(strict_types=1);

/**
 * Построение структуры деталей комментария
 * 
 * Ответственность:
 * - Построение структуры деталей комментария
 * - Построение fallback-данных
 * - Определение типа комментария
 */
class CommentBuilder
{
    private EntityIdentityService $identity;
    private RequestService $request;
    private TaskDetailsService $taskDetails;
    private ErrorService $errors;

    public function __construct(
        EntityIdentityService $identity,
        RequestService $request,
        TaskDetailsService $taskDetails,
        ErrorService $errors
    ) {
        $this->identity = $identity;
        $this->request = $request;
        $this->taskDetails = $taskDetails;
        $this->errors = $errors;
    }

    /**
     * Построение деталей комментария из данных REST API
     */
    public function build(
        array $commentData,
        string $eventType,
        ?string $requestId,
        string $taskId,
        string $commentId,
        string $sourceMethod,
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
        // Оценка типа Activity (новый формат)
        $details['activityType'] = $this->taskDetails->evaluateActivity($details);
        // Обратная совместимость: activityFirst для старых обработчиков
        $details['activityFirst'] = $details['activityType'] !== null;
        return $details;
    }

    /**
     * Построение деталей комментария из данных чата
     */
    public function buildFromChat(
        array $messageData,
        string $eventType,
        ?string $requestId,
        string $taskId,
        string $commentId,
        string $sourceMethod,
        ?array $taskData = null
    ): array {
        // Временное логирование для отладки структуры данных (без полных данных для экономии места)
        $this->errors->log('CommentBuilder::buildFromChat - messageData structure', [
            'messageDataKeys' => array_keys($messageData),
            'hasParams' => isset($messageData['params']),
            'paramsKeys' => isset($messageData['params']) && is_array($messageData['params']) ? array_keys($messageData['params']) : null,
            'hasAuthorId' => isset($messageData['author_id']) || isset($messageData['AUTHOR_ID']),
            'hasText' => isset($messageData['text']) || isset($messageData['TEXT']),
            'hasDate' => isset($messageData['date']) || isset($messageData['DATE']),
            'sampleValues' => [
                'author_id' => $messageData['author_id'] ?? $messageData['AUTHOR_ID'] ?? 'not-found',
                'text' => isset($messageData['text']) ? substr($messageData['text'], 0, 50) : (isset($messageData['TEXT']) ? substr($messageData['TEXT'], 0, 50) : 'not-found'),
            ],
        ]);
        
        $meta = $this->taskDetails->extractMeta($taskData);
        $fileIds = [];
        
        // Извлечение файлов из разных мест структуры данных
        // 1. Из params.FILE_ID
        if (isset($messageData['params']) && is_array($messageData['params'])) {
            $params = $messageData['params'];
            if (isset($params['FILE_ID']) && is_array($params['FILE_ID'])) {
                foreach ($params['FILE_ID'] as $fileId) {
                    if ($fileId !== null && $fileId !== '') {
                        $fileIds[] = (string) $fileId;
                    }
                }
            }
            // 2. Из params.ATTACH
            if (isset($params['ATTACH']) && is_array($params['ATTACH'])) {
                foreach ($params['ATTACH'] as $attach) {
                    if (is_array($attach)) {
                        if (isset($attach['ID'])) {
                            $fileIds[] = (string) $attach['ID'];
                        }
                        if (isset($attach['FILE_ID'])) {
                            $fileIds[] = (string) $attach['FILE_ID'];
                        }
                    }
                }
            }
            // 3. Из params.FILES
            if (isset($params['FILES']) && is_array($params['FILES'])) {
                foreach ($params['FILES'] as $file) {
                    if (is_array($file) && isset($file['ID'])) {
                        $fileIds[] = (string) $file['ID'];
                    } elseif (is_string($file) || is_numeric($file)) {
                        $fileIds[] = (string) $file;
                    }
                }
            }
        }
        
        // 4. Из корня messageData (если файлы там)
        if (isset($messageData['FILES']) && is_array($messageData['FILES'])) {
            foreach ($messageData['FILES'] as $file) {
                if (is_array($file) && isset($file['ID'])) {
                    $fileIds[] = (string) $file['ID'];
                } elseif (is_string($file) || is_numeric($file)) {
                    $fileIds[] = (string) $file;
                }
            }
        }
        if (isset($messageData['FILE_ID']) && is_array($messageData['FILE_ID'])) {
            foreach ($messageData['FILE_ID'] as $fileId) {
                if ($fileId !== null && $fileId !== '') {
                    $fileIds[] = (string) $fileId;
                }
            }
        }
        
        $fileIds = array_values(array_unique($fileIds));

        // Извлечение authorId с расширенным поиском
        // im.dialog.messages.get использует 'author_id' (в нижнем регистре)
        $authorId = $this->request->getFirstValue($messageData, [
            'author_id', 'AUTHOR_ID', 'authorId',  // Основной вариант для im.dialog.messages.get
            'FROM_ID', 'from_id', 'fromId',
            'USER_ID', 'user_id', 'userId',
            'FROM_USER_ID', 'from_user_id', 'fromUserId',
            ['author', 'id'],
            ['from', 'id'],
            ['user', 'id']
        ]);
        
        // Временное логирование для отладки
        if ($authorId === null) {
            $this->errors->log('CommentBuilder::buildFromChat - authorId not found', [
                'messageData' => $messageData,
                'messageDataKeys' => array_keys($messageData),
            ]);
        }
        
        $details = [
            'loggedAt' => $this->request->now(),
            'requestId' => $requestId ?? 'unknown',
            'eventType' => $eventType,
            'taskId' => $taskId ?? 'unknown',
            'commentId' => $commentId ?? 'unknown',
            'authorId' => $authorId ?? 'unknown',
            'message' => $this->request->getFirstValue($messageData, [
                'text', 'TEXT',  // Основной вариант для im.dialog.messages.get
                'MESSAGE', 'message',
                'MESSAGE_TEXT', 'message_text',
                'BODY', 'body',
                'CONTENT', 'content'
            ]) ?? 'unknown',
            'createdAt' => $this->request->getFirstValue($messageData, [
                'date', 'DATE',  // Основной вариант для im.dialog.messages.get
                'DATE_CREATE', 'date_create', 
                'DATE_INSERT', 'date_insert',
                'CREATED', 'created',
                'TIMESTAMP', 'timestamp'
            ]) ?? 'unknown',
            'sourceMethod' => $sourceMethod ?? 'unknown',
            'fileIds' => $fileIds,
            'projectId' => $meta['projectId'],
            'projectName' => $meta['projectName'],
            'crmLinks' => $meta['crmLinks'],
        ];

        $details['commentKind'] = $this->resolveKind($details['authorId'] ?? null);
        // Оценка типа Activity (новый формат)
        $details['activityType'] = $this->taskDetails->evaluateActivity($details);
        // Обратная совместимость: activityFirst для старых обработчиков
        $details['activityFirst'] = $details['activityType'] !== null;
        
        // Временное логирование для отладки
        $this->errors->log('CommentBuilder::buildFromChat - built details', [
            'authorId' => $details['authorId'],
            'message' => $details['message'],
            'fileIds' => $details['fileIds'],
            'commentKind' => $details['commentKind'],
            'activityType' => $details['activityType'],
        ]);
        
        return $details;
    }

    /**
     * Построение fallback-данных
     */
    public function buildFallback(
        string $eventType,
        ?string $requestId,
        string $taskId,
        string $commentId
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

    /**
     * Определение типа комментария
     */
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
}
