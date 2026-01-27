<?php
declare(strict_types=1);

/**
 * Обработчик событий комментариев
 * 
 * Ответственность:
 * - Обработка событий комментариев (ONTASKCOMMENTADD)
 * - Получение деталей комментария
 * - Обработка ActivityFirst (синхронная)
 */
class CommentEventHandler
{
    private CommentDetailsService $commentDetails;
    private RestService $rest;
    private TaskDetailsService $taskDetails;
    private EntityIdentityService $identity;
    private ErrorService $errors;
    private ConfigService $config;
    private ?array $lastDetails = null;

    public function __construct(
        CommentDetailsService $commentDetails,
        RestService $rest,
        TaskDetailsService $taskDetails,
        EntityIdentityService $identity,
        ErrorService $errors,
        ConfigService $config
    ) {
        $this->commentDetails = $commentDetails;
        $this->rest = $rest;
        $this->taskDetails = $taskDetails;
        $this->identity = $identity;
        $this->errors = $errors;
        $this->config = $config;
    }

    public function getLastDetails(): ?array
    {
        return $this->lastDetails;
    }

    /**
     * Обработка события комментария
     * 
     * @param string $eventType Тип события
     * @param array $payload Payload события
     * @param ?string $entityId ID сущности (задачи)
     * @param string $requestId ID запроса
     * @param string $rawPath Путь к raw.json
     * @return bool true если требуется синхронная обработка ActivityFirst
     */
    public function handle(
        string $eventType,
        array $payload,
        ?string $entityId,
        string $requestId,
        string $rawPath
    ): bool {
        if ($entityId === null || $eventType !== 'ONTASKCOMMENTADD') {
            return false;
        }

        $commentId = $this->identity->extractCommentId($payload);
        $messageId = $this->identity->extractMessageId($payload);
        
        if ($commentId === null) {
            return false;
        }

        try {
            $restCall = fn(string $method, array $params = []) => $this->rest->call($method, $params);
            
            // Получение данных комментария через CommentDetailsService (который использует CommentFetcher)
            // Передаем messageId для fallback через чат
            $fetch = $this->commentDetails->fetchDetails($restCall, $entityId, $commentId, $messageId);
            
            // Получение данных задачи для обогащения (нужно для buildDetails)
            $taskResult = $this->rest->call('tasks.task.get', ['id' => $entityId]);
            $taskPayload = $taskResult['result'] ?? $taskResult;
            $taskData = is_array($taskPayload) ? ($taskPayload['task'] ?? $taskPayload) : null;
            $commentWritten = false;
            $commentData = null;
            $commentSource = null;
            $details = null;

            if (is_array($fetch['data'])) {
                if (is_array($taskData)) {
                    $taskData = $this->taskDetails->ensureCrmLinks($taskData, $entityId, $restCall);
                }
                
                $commentData = $fetch['data'];
                $commentSource = $fetch['method'];
                $details = $this->commentDetails->buildDetails(
                    $fetch['data'],
                    $eventType,
                    $requestId,
                    $entityId,
                    $commentId,
                    $fetch['method'],
                    $taskData
                );
                $this->commentDetails->writeDetailsRu($eventType, $details);
                $this->lastDetails = $details;
                $commentWritten = true;
            } else {
                // Fallback: попытка получить через чат (если есть messageId)
                if ($messageId !== null && is_array($taskData)) {
                    $taskData = $this->taskDetails->ensureCrmLinks($taskData, $entityId, $restCall);
                    $chatId = $this->commentDetails->extractChatId($taskData);
                    
                    if ($chatId !== null) {
                        $chatFetch = $this->commentDetails->fetchChatMessageDetails($restCall, $chatId, $messageId);
                        if (is_array($chatFetch['data'])) {
                            $commentData = $chatFetch['data'];
                            $commentSource = $chatFetch['method'];
                            $details = $this->commentDetails->buildDetailsFromChat(
                                $chatFetch['data'],
                                $eventType,
                                $requestId,
                                $entityId,
                                $commentId,
                                $chatFetch['method'],
                                $taskData
                            );
                            $this->commentDetails->writeDetailsRu($eventType, $details);
                            $this->lastDetails = $details;
                            $commentWritten = true;
                        }
                    }
                }

                // Если не удалось получить данные, создаем fallback
                if (!$commentWritten) {
                    $fallback = $this->commentDetails->buildFallback($eventType, $requestId, $entityId, $commentId);
                    $this->commentDetails->writeDetailsRu($eventType, $fallback);
                    $this->errors->log('Comment details missing', [
                        'requestId' => $requestId,
                        'taskId' => $entityId,
                        'commentId' => $commentId,
                        'messageId' => $messageId,
                        'errors' => $fetch['errors'] ?? [],
                    ]);
                }
            }

            // Запись обогащенных данных (если требуется)
            if ($commentWritten && $this->commentDetails->shouldWriteEnriched($entityId) && is_array($commentData)) {
                if ($taskData === null) {
                    $taskResult = $this->rest->call('tasks.task.get', ['id' => $entityId]);
                    $taskPayload = $taskResult['result'] ?? $taskResult;
                    $taskData = is_array($taskPayload) ? ($taskPayload['task'] ?? $taskPayload) : null;
                }
                if (is_array($taskData)) {
                    $this->commentDetails->writeEnriched(
                        $eventType,
                        $entityId,
                        $taskData,
                        $commentData,
                        $commentSource ?? 'unknown',
                        $rawPath,
                        $requestId
                    );
                }
            }

            // Проверка необходимости синхронной обработки Activity
            // Поддерживаем как новый формат (activityType), так и старый (activityFirst)
            if ($commentWritten && is_array($details)) {
                $activityType = $details['activityType'] ?? null;
                $activityFirst = $details['activityFirst'] ?? false;
                
                if ($activityType !== null || $activityFirst) {
                    return true;
                }
            }

            return false;
        } catch (Throwable $e) {
            $this->errors->log('Comment details exception', [
                'requestId' => $requestId,
                'taskId' => $entityId,
                'commentId' => $commentId ?? null,
                'messageId' => $messageId ?? null,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
