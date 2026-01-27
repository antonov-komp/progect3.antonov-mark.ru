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

            // Обработка структуры ответа im.dialog.messages.get
            // Ответ может быть: {result: {messages: [...]}} или {messages: [...]} или просто массив сообщений
            $messagesList = null;
            if (isset($result['result']) && is_array($result['result'])) {
                // Вариант 1: {result: {messages: [...]}}
                $messagesList = $result['result']['messages'] ?? $result['result'];
            } elseif (isset($result['messages']) && is_array($result['messages'])) {
                // Вариант 2: {messages: [...]}
                $messagesList = $result['messages'];
            } elseif (is_array($result) && isset($result[0]) && is_array($result[0])) {
                // Вариант 3: массив сообщений напрямую
                $messagesList = $result;
            }

            if (!is_array($messagesList) || empty($messagesList)) {
                $errors[] = ['method' => $attempt['method'], 'error' => 'no_messages'];
                continue;
            }

            // Логирование структуры ответа для отладки
            $this->errors->log('CommentFetcher::fetchFromChat - API response structure', [
                'method' => $attempt['method'],
                'messagesCount' => count($messagesList),
                'messageId' => $messageId,
                'firstMessageKeys' => !empty($messagesList) && is_array($messagesList[0]) ? array_keys($messagesList[0]) : null,
            ]);

            // Поиск сообщения в массиве
            $message = $this->findChatMessage(['messages' => $messagesList], $messageId);
            if (is_array($message)) {
                // Логирование найденного сообщения
                $this->errors->log('CommentFetcher::fetchFromChat - message found', [
                    'messageId' => $messageId,
                    'messageKeys' => array_keys($message),
                    'hasAuthorId' => isset($message['author_id']) || isset($message['AUTHOR_ID']),
                    'hasText' => isset($message['text']) || isset($message['TEXT']),
                    'hasParams' => isset($message['params']),
                ]);
                return ['data' => $message, 'method' => $attempt['method'], 'errors' => $errors];
            } else {
                // Логирование, если сообщение не найдено
                $this->errors->log('CommentFetcher::fetchFromChat - message not found', [
                    'messageId' => $messageId,
                    'messagesCount' => count($messagesList),
                    'sampleIds' => array_slice(array_map(function($msg) {
                        return is_array($msg) ? ($msg['id'] ?? $msg['ID'] ?? 'no-id') : 'not-array';
                    }, $messagesList), 0, 5),
                ]);
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
     * 
     * Структура ответа im.dialog.messages.get:
     * - result.messages[] или messages[]
     * - Каждое сообщение имеет: id, author_id, date, text, params
     */
    private function findChatMessage(array $payload, string $messageId): ?array
    {
        // Нормализация messageId для сравнения (убираем пробелы, приводим к строке)
        $messageId = trim((string) $messageId);
        
        $listKeys = ['messages', 'list', 'items', 'result', 'data'];
        foreach ($listKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach ($payload[$key] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    // im.dialog.messages.get использует 'id' (в нижнем регистре)
                    // Также проверяем различные варианты ключей
                    $itemId = $this->request->getFirstValue($item, [
                        'id', 'ID',  // Основной вариант для im.dialog.messages.get
                        'messageId', 'MESSAGE_ID', 'message_id',
                        'MESSAGE_ID', 'MESSAGEID',
                        ['message', 'id'],
                        ['data', 'id']
                    ]);
                    
                    // Сравнение с нормализацией (приводим к строке и убираем пробелы)
                    if ($itemId !== null && trim((string) $itemId) === $messageId) {
                        return $item;
                    }
                }
            }
        }
        
        // Если payload сам является массивом сообщений (нумерованный массив)
        if (!empty($payload) && array_keys($payload) === range(0, count($payload) - 1)) {
            foreach ($payload as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemId = $this->request->getFirstValue($item, [
                    'id', 'ID',  // Основной вариант для im.dialog.messages.get
                    'messageId', 'MESSAGE_ID', 'message_id',
                    'MESSAGE_ID', 'MESSAGEID'
                ]);
                if ($itemId !== null && trim((string) $itemId) === $messageId) {
                    return $item;
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
