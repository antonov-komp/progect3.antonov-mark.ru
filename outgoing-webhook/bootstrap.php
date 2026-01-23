<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Minsk');

const OUTGOING_WEBHOOK_MAX_BYTES = 2097152; // 2 MB

function outgoingWebhookNow(): string
{
    return date('c');
}

function outgoingWebhookGetEnv(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function outgoingWebhookGetConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configPath = __DIR__ . '/config.local.php';
    if (file_exists($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $config = $loaded;
            return $config;
        }
    }

    $config = [];
    return $config;
}

function outgoingWebhookGetSetting(string $key, ?string $default = null): ?string
{
    $envValue = outgoingWebhookGetEnv($key);
    if ($envValue !== null) {
        return $envValue;
    }

    $config = outgoingWebhookGetConfig();
    if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
        return $config[$key];
    }

    return $default;
}

function outgoingWebhookSafeMkdir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function outgoingWebhookWriteJson(string $path, array $data): bool
{
    outgoingWebhookSafeMkdir(dirname($path));

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = json_encode(['error' => 'json_encode_failed']);
    }

    return file_put_contents($path, $json . PHP_EOL) !== false;
}

function outgoingWebhookAppendLine(string $path, string $line): bool
{
    outgoingWebhookSafeMkdir(dirname($path));

    return file_put_contents($path, $line . PHP_EOL, FILE_APPEND) !== false;
}

function outgoingWebhookLogError(string $message, array $context = []): void
{
    $logPath = __DIR__ . '/logs/errors/error-' . date('Ymd') . '.log';
    $entry = [
        'loggedAt' => outgoingWebhookNow(),
        'message' => $message,
        'context' => $context,
    ];

    outgoingWebhookAppendLine($logPath, json_encode($entry, JSON_UNESCAPED_SLASHES));
    error_log('[outgoing-webhook] ' . $message . ' ' . json_encode($context));
}

function outgoingWebhookMaskValue(string $value): string
{
    $suffix = substr($value, -4);
    return '****' . $suffix;
}

function outgoingWebhookMaskPayload(array $payload): array
{
    foreach ($payload as $key => $value) {
        if (is_array($value)) {
            $payload[$key] = outgoingWebhookMaskPayload($value);
            continue;
        }

        if (is_string($value) && in_array(strtolower((string) $key), ['token'], true)) {
            $payload[$key] = outgoingWebhookMaskValue($value);
        }
    }

    return $payload;
}

function outgoingWebhookNormalizeLogValue($value): string
{
    if ($value === null) {
        return 'unknown';
    }

    $text = trim((string) $value);
    if ($text === '') {
        return 'unknown';
    }

    $text = preg_replace('/\s+/u', ' ', $text);
    return $text ?? 'unknown';
}

function outgoingWebhookGenerateRequestId(): string
{
    return bin2hex(random_bytes(16));
}

function outgoingWebhookGetAllowedIps(): array
{
    $setting = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_ALLOWED_IPS');
    if ($setting === null) {
        return [];
    }

    if (is_string($setting)) {
        $items = array_filter(array_map('trim', explode(',', $setting)));
        return array_values(array_unique($items));
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
        return array_values(array_unique($items));
    }

    return [];
}

function outgoingWebhookReadPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $rawBody = file_get_contents('php://input');

    if (stripos($contentType, 'application/json') !== false && $rawBody !== false) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    if ($rawBody !== false && $rawBody !== '') {
        parse_str($rawBody, $parsed);
        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }
    }

    return [];
}

function outgoingWebhookNormalizeEventType(?string $event): string
{
    $event = $event ?? '';
    $event = strtoupper(trim(str_replace(' ', '', $event)));
    $event = preg_replace('/[^A-Z0-9_]/', '', $event);
    return $event !== '' ? $event : 'UNKNOWN';
}

function outgoingWebhookExtractEntityId(array $payload): ?string
{
    $data = $payload['data'] ?? [];
    if (is_array($data)) {
        if (isset($data['FIELDS_AFTER']['TASK_ID'])) {
            return (string) $data['FIELDS_AFTER']['TASK_ID'];
        }
        if (isset($data['FIELDS']['TASK_ID'])) {
            return (string) $data['FIELDS']['TASK_ID'];
        }
        if (isset($data['TASK_ID'])) {
            return (string) $data['TASK_ID'];
        }
        if (isset($data['FIELDS']['ID'])) {
            return (string) $data['FIELDS']['ID'];
        }
        if (isset($data['FIELDS_AFTER']['ID'])) {
            return (string) $data['FIELDS_AFTER']['ID'];
        }
        if (isset($data['FIELDS_BEFORE']['ID'])) {
            return (string) $data['FIELDS_BEFORE']['ID'];
        }
        if (isset($data['ID'])) {
            return (string) $data['ID'];
        }
    }

    return null;
}

function outgoingWebhookExtractCommentId(array $payload): ?string
{
    $data = $payload['data'] ?? [];
    if (!is_array($data)) {
        return null;
    }

    $paths = [
        ['FIELDS_AFTER', 'MESSAGE_ID'],
        ['FIELDS', 'MESSAGE_ID'],
        ['FIELDS_AFTER', 'ID'],
        ['FIELDS', 'ID'],
        ['MESSAGE_ID'],
        ['ID'],
    ];

    foreach ($paths as $path) {
        $value = outgoingWebhookGetFirstValue($data, [$path]);
        if ($value !== null) {
            return (string) $value;
        }
    }

    return null;
}

function outgoingWebhookExtractAuthToken(array $payload): string
{
    if (isset($payload['token']) && is_string($payload['token'])) {
        return $payload['token'];
    }

    if (isset($payload['auth']) && is_array($payload['auth'])) {
        $auth = $payload['auth'];
        if (isset($auth['application_token']) && is_string($auth['application_token'])) {
            return $auth['application_token'];
        }
        if (isset($auth['app_token']) && is_string($auth['app_token'])) {
            return $auth['app_token'];
        }
    }

    return '';
}

function outgoingWebhookExtractAuthInfo(array $payload): array
{
    if (isset($payload['token']) && is_string($payload['token'])) {
        return ['token' => $payload['token'], 'source' => 'token'];
    }

    if (isset($payload['auth']) && is_array($payload['auth'])) {
        $auth = $payload['auth'];
        if (isset($auth['application_token']) && is_string($auth['application_token'])) {
            return ['token' => $auth['application_token'], 'source' => 'auth.application_token'];
        }
        if (isset($auth['app_token']) && is_string($auth['app_token'])) {
            return ['token' => $auth['app_token'], 'source' => 'auth.app_token'];
        }
    }

    return ['token' => '', 'source' => 'none'];
}

function outgoingWebhookGetFirstValue(array $data, array $keys)
{
    foreach ($keys as $key) {
        if (is_array($key)) {
            $value = $data;
            $found = true;
            foreach ($key as $path) {
                if (!is_array($value) || !array_key_exists($path, $value)) {
                    $found = false;
                    break;
                }
                $value = $value[$path];
            }
            if ($found && $value !== null && $value !== '') {
                return $value;
            }
            continue;
        }

        if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
            return $data[$key];
        }
    }

    return null;
}

function outgoingWebhookExtractTaskData(array $enriched): ?array
{
    $taskData = $enriched['data']['task'] ?? null;
    if (!is_array($taskData)) {
        return null;
    }

    if (isset($taskData['task']) && is_array($taskData['task'])) {
        return $taskData['task'];
    }

    return $taskData;
}

function outgoingWebhookBuildTaskDetails(
    array $taskData,
    string $eventType,
    ?string $requestId,
    ?string $entityId
): array {
    return [
        'loggedAt' => outgoingWebhookNow(),
        'requestId' => $requestId ?? 'unknown',
        'eventType' => $eventType,
        'taskId' => $entityId
            ?? outgoingWebhookGetFirstValue($taskData, ['ID', 'id'])
            ?? 'unknown',
        'title' => outgoingWebhookGetFirstValue($taskData, ['TITLE', 'NAME', 'title']) ?? 'unknown',
        'createdBy' => outgoingWebhookGetFirstValue(
            $taskData,
            ['CREATED_BY', 'CREATED_BY_ID', 'createdBy', ['creator', 'id']]
        ) ?? 'unknown',
        'groupId' => outgoingWebhookGetFirstValue(
            $taskData,
            ['GROUP_ID', 'PROJECT_ID', 'groupId', ['group', 'id']]
        ) ?? 'unknown',
        'deadline' => outgoingWebhookGetFirstValue($taskData, ['DEADLINE', 'deadline']) ?? 'unknown',
        'startDatePlan' => outgoingWebhookGetFirstValue($taskData, ['START_DATE_PLAN', 'startDatePlan']) ?? 'unknown',
        'endDatePlan' => outgoingWebhookGetFirstValue($taskData, ['END_DATE_PLAN', 'endDatePlan']) ?? 'unknown',
    ];
}

function outgoingWebhookFormatTaskDetailsRu(array $details): string
{
    return sprintf(
        'Дата=%s | requestId=%s | Событие=%s | Задача=%s | Название=%s | Постановщик=%s | Проект=%s | Срок=%s | ПланСтарт=%s | ПланФиниш=%s',
        $details['loggedAt'] ?? 'unknown',
        $details['requestId'] ?? 'unknown',
        $details['eventType'] ?? 'unknown',
        $details['taskId'] ?? 'unknown',
        outgoingWebhookNormalizeLogValue($details['title'] ?? 'unknown'),
        $details['createdBy'] ?? 'unknown',
        $details['groupId'] ?? 'unknown',
        $details['deadline'] ?? 'unknown',
        $details['startDatePlan'] ?? 'unknown',
        $details['endDatePlan'] ?? 'unknown'
    );
}

function outgoingWebhookWriteTaskDetailsRu(string $eventType, array $details): void
{
    if (!str_starts_with($eventType, 'ONTASK')) {
        return;
    }

    $eventDir = __DIR__ . '/logs/' . $eventType;
    outgoingWebhookSafeMkdir($eventDir);
    outgoingWebhookAppendLine($eventDir . '/task-details.log', outgoingWebhookFormatTaskDetailsRu($details));
}

function outgoingWebhookBuildCommentDetails(
    array $commentData,
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId,
    ?string $sourceMethod
): array {
    $resolvedCommentId = outgoingWebhookGetFirstValue(
        $commentData,
        ['ID', 'id', 'COMMENT_ID', 'MESSAGE_ID', 'messageId']
    );

    return [
        'loggedAt' => outgoingWebhookNow(),
        'requestId' => $requestId ?? 'unknown',
        'eventType' => $eventType,
        'taskId' => $taskId ?? 'unknown',
        'commentId' => $commentId ?? ($resolvedCommentId ?? 'unknown'),
        'authorId' => outgoingWebhookGetFirstValue(
            $commentData,
            ['AUTHOR_ID', 'CREATED_BY', 'authorId', 'createdBy', ['author', 'id']]
        ) ?? 'unknown',
        'message' => outgoingWebhookGetFirstValue(
            $commentData,
            ['POST_MESSAGE', 'MESSAGE', 'TEXT', 'text', 'postMessage', 'content', 'message']
        ) ?? 'unknown',
        'createdAt' => outgoingWebhookGetFirstValue(
            $commentData,
            ['POST_DATE', 'CREATED_DATE', 'createdDate', 'dateCreate', 'date']
        ) ?? 'unknown',
        'sourceMethod' => $sourceMethod ?? 'unknown',
    ];
}

function outgoingWebhookBuildCommentFallback(
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId
): array {
    return [
        'loggedAt' => outgoingWebhookNow(),
        'requestId' => $requestId ?? 'unknown',
        'eventType' => $eventType,
        'taskId' => $taskId ?? 'unknown',
        'commentId' => $commentId ?? 'unknown',
        'authorId' => 'unknown',
        'message' => 'контекст не доступен в REST',
        'createdAt' => 'unknown',
        'sourceMethod' => 'fallback',
    ];
}

function outgoingWebhookFormatCommentDetailsRu(array $details): string
{
    return sprintf(
        'Дата=%s | requestId=%s | Событие=%s | Задача=%s | КомментарийID=%s | Автор=%s | Создано=%s | Текст=%s | Метод=%s',
        $details['loggedAt'] ?? 'unknown',
        $details['requestId'] ?? 'unknown',
        $details['eventType'] ?? 'unknown',
        $details['taskId'] ?? 'unknown',
        $details['commentId'] ?? 'unknown',
        $details['authorId'] ?? 'unknown',
        $details['createdAt'] ?? 'unknown',
        outgoingWebhookNormalizeLogValue($details['message'] ?? 'unknown'),
        $details['sourceMethod'] ?? 'unknown'
    );
}

function outgoingWebhookWriteCommentDetailsRu(string $eventType, array $details): void
{
    if (!str_starts_with($eventType, 'ONTASK')) {
        return;
    }

    $eventDir = __DIR__ . '/logs/' . $eventType;
    outgoingWebhookSafeMkdir($eventDir);
    outgoingWebhookAppendLine($eventDir . '/comment-details.log', outgoingWebhookFormatCommentDetailsRu($details));
}

function outgoingWebhookFindCommentItem($payload, string $commentId): ?array
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
                $itemId = outgoingWebhookGetFirstValue($item, ['ID', 'id', 'COMMENT_ID', 'MESSAGE_ID', 'messageId']);
                if ($itemId !== null && (string) $itemId === (string) $commentId) {
                    return $item;
                }
            }
        }
    }

    if (array_keys($payload) === range(0, count($payload) - 1)) {
        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = outgoingWebhookGetFirstValue($item, ['ID', 'id', 'COMMENT_ID', 'MESSAGE_ID', 'messageId']);
            if ($itemId !== null && (string) $itemId === (string) $commentId) {
                return $item;
            }
        }
    }

    return $payload;
}

function outgoingWebhookFetchCommentDetails(callable $restCall, string $taskId, string $commentId): array
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

        $found = outgoingWebhookFindCommentItem($payloadData, $commentId);
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

function outgoingWebhookResolveEntityType(string $eventType): string
{
    if (str_starts_with($eventType, 'ONCRMDEAL')) {
        return 'deal';
    }
    if (str_starts_with($eventType, 'ONCRMLEAD')) {
        return 'lead';
    }
    if (str_starts_with($eventType, 'ONCRMCONTACT')) {
        return 'contact';
    }
    if (str_starts_with($eventType, 'ONCRMCOMPANY')) {
        return 'company';
    }
    if (str_starts_with($eventType, 'ONCRMITEM')) {
        return 'smart_process';
    }
    if (str_starts_with($eventType, 'ONTASK')) {
        return 'task';
    }
    if (str_starts_with($eventType, 'ONUSER')) {
        return 'user';
    }
    if (str_starts_with($eventType, 'SONET_GROUP')) {
        return 'project';
    }
    if (str_starts_with($eventType, 'ONCRMUSERFIELD')) {
        return 'crm_userfield';
    }

    return 'unknown';
}

function outgoingWebhookJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}
