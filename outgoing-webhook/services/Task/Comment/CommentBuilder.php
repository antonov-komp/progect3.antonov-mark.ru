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

    public function __construct(
        EntityIdentityService $identity,
        RequestService $request,
        TaskDetailsService $taskDetails
    ) {
        $this->identity = $identity;
        $this->request = $request;
        $this->taskDetails = $taskDetails;
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
        $details['activityFirst'] = $this->taskDetails->evaluateActivityFirst($details);
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
