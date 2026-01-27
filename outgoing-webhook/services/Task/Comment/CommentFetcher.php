<?php
declare(strict_types=1);

/**
 * Получение деталей комментария
 * 
 * Ответственность:
 * - Получение деталей комментария через REST API
 * - Получение деталей комментария через чат API
 * - Fallback-логика
 */
class CommentFetcher
{
    private RestService $rest;
    private EntityIdentityService $identity;
    private TaskDetailsService $taskDetails;
    private RequestService $request;
    private ErrorService $errors;

    public function __construct(
        RestService $rest,
        EntityIdentityService $identity,
        TaskDetailsService $taskDetails,
        RequestService $request,
        ErrorService $errors
    ) {
        $this->rest = $rest;
        $this->identity = $identity;
        $this->taskDetails = $taskDetails;
        $this->request = $request;
        $this->errors = $errors;
    }

    /**
     * Получение деталей комментария
     * 
     * @param string $taskId ID задачи
     * @param string $commentId ID комментария
     * @param ?string $messageId ID сообщения (для fallback через чат)
     * @param ?array $taskData Данные задачи (для fallback через чат)
     * @return array ['data' => array|null, 'method' => string|null, 'errors' => array]
     */
    public function fetch(string $taskId, string $commentId, ?string $messageId, ?array $taskData): array
    {
        $restCall = fn(string $method, array $params = []) => $this->rest->call($method, $params);
        
        // Попытка получить через task.commentitem.get
        $fetch = $this->fetchFromTask($restCall, $taskId, $commentId);
        if (is_array($fetch['data'])) {
            return $fetch;
        }

        // Fallback: получение через чат
        if ($messageId !== null && is_array($taskData)) {
            $taskData = $this->taskDetails->ensureCrmLinks($taskData, $taskId, $restCall);
            $chatId = $this->extractChatId($taskData);
            if ($chatId !== null) {
                $chatFetch = $this->fetchFromChat($restCall, $chatId, $messageId);
                if (is_array($chatFetch['data'])) {
                    return $chatFetch;
                }
            }
        }

        return ['data' => null, 'method' => null, 'errors' => $fetch['errors'] ?? []];
    }

    /**
     * Получение комментария через task.commentitem.get
     */
    private function fetchFromTask(callable $restCall, string $taskId, string $commentId): array
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

    /**
     * Получение комментария через чат API
     */
    private function fetchFromChat(callable $restCall, string $chatId, string $messageId): array
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

    /**
     * Поиск комментария в payload
     */
    private function findCommentItem($payload, string $commentId): ?array
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

    /**
     * Поиск сообщения в payload чата
     */
    private function findChatMessage(array $payload, string $messageId): ?array
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

    /**
     * Извлечение ID чата из данных задачи
     */
    private function extractChatId(array $taskData): ?string
    {
        $value = $this->request->getFirstValue($taskData, ['chatId', 'CHAT_ID', 'chat_id']);
        return $value !== null ? (string) $value : null;
    }
}
