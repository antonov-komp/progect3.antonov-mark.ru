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

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

function outgoingWebhookNormalizeEntityId(?string $entityId): ?string
{
    if ($entityId === null) {
        return null;
    }

    $value = trim((string) $entityId);
    if ($value === '' || $value === '0') {
        return null;
    }

    return $value;
}

function outgoingWebhookExtractTaskId(array $payload): ?string
{
    $data = $payload['data'] ?? [];
    if (!is_array($data)) {
        return null;
    }

    $value = outgoingWebhookGetFirstValue($data, [
        ['FIELDS_AFTER', 'TASK_ID'],
        ['FIELDS', 'TASK_ID'],
        'TASK_ID',
    ]);

    return $value !== null ? (string) $value : null;
}

function outgoingWebhookExtractMessageId(array $payload): ?string
{
    $data = $payload['data'] ?? [];
    if (!is_array($data)) {
        return null;
    }

    $value = outgoingWebhookGetFirstValue($data, [
        ['FIELDS_AFTER', 'MESSAGE_ID'],
        ['FIELDS', 'MESSAGE_ID'],
        'MESSAGE_ID',
    ]);

    return $value !== null ? (string) $value : null;
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

function outgoingWebhookExtractTaskMeta(?array $taskData): array
{
    if (!is_array($taskData)) {
        return [
            'projectId' => 'unknown',
            'projectName' => 'unknown',
            'crmLinks' => [],
        ];
    }

    $projectId = outgoingWebhookGetFirstValue($taskData, ['GROUP_ID', 'groupId', ['group', 'id']]) ?? 'unknown';
    $projectName = outgoingWebhookGetFirstValue($taskData, ['GROUP_NAME', ['group', 'name']]) ?? 'unknown';

    $crmLinks = [];
    if (isset($taskData['UF_CRM_TASK']) && is_array($taskData['UF_CRM_TASK'])) {
        foreach ($taskData['UF_CRM_TASK'] as $link) {
            if ($link !== null && $link !== '') {
                $crmLinks[] = (string) $link;
            }
        }
    }
    $crmLinks = array_values(array_unique($crmLinks));

    return [
        'projectId' => (string) $projectId,
        'projectName' => (string) $projectName,
        'crmLinks' => $crmLinks,
    ];
}

function outgoingWebhookLoadActivityFirstConditions(): array
{
    $path = __DIR__ . '/activity/first/conditions.php';
    if (file_exists($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            return $loaded;
        }
    }

    return [
        'projectId' => null,
        'crmDealPrefix' => 'D_',
        'keywords' => [],
    ];
}

function outgoingWebhookMessageHasKeyword(string $message, array $keywords): bool
{
    foreach ($keywords as $keyword) {
        if (!is_string($keyword) || $keyword === '') {
            continue;
        }
        if (function_exists('mb_stripos')) {
            if (mb_stripos($message, $keyword) !== false) {
                return true;
            }
        } else {
            if (stripos($message, $keyword) !== false) {
                return true;
            }
        }
    }

    return false;
}

function outgoingWebhookHasDealLink(array $crmLinks, string $dealPrefix): bool
{
    foreach ($crmLinks as $link) {
        if (!is_string($link)) {
            continue;
        }
        if ($dealPrefix !== '' && str_starts_with($link, $dealPrefix)) {
            return true;
        }
        if (stripos($link, '/crm/deal/') !== false) {
            return true;
        }
    }

    return false;
}

function outgoingWebhookEvaluateActivityFirst(array $details): bool
{
    $conditions = outgoingWebhookLoadActivityFirstConditions();
    $projectId = (string) ($conditions['projectId'] ?? '');
    $dealPrefix = (string) ($conditions['crmDealPrefix'] ?? 'D_');
    $keywords = is_array($conditions['keywords'] ?? null) ? $conditions['keywords'] : [];

    if ($projectId !== '' && ($details['projectId'] ?? '') !== $projectId) {
        return false;
    }

    $crmLinks = is_array($details['crmLinks'] ?? null) ? $details['crmLinks'] : [];
    if (!outgoingWebhookHasDealLink($crmLinks, $dealPrefix)) {
        return false;
    }

    $message = (string) ($details['message'] ?? '');
    if ($message === '') {
        return false;
    }

    if (!outgoingWebhookMessageHasKeyword($message, $keywords)) {
        return false;
    }

    $fileIds = $details['fileIds'] ?? [];
    if (!is_array($fileIds) || empty($fileIds)) {
        return false;
    }

    return true;
}

function outgoingWebhookLoadTaskCrmLinks(string $taskId, callable $restCall): array
{
    $result = $restCall('task.item.getdata', ['TASKID' => (int) $taskId]);
    if (!is_array($result) || !empty($result['error'])) {
        return [];
    }

    $data = $result['result'] ?? [];
    if (!is_array($data)) {
        return [];
    }

    $links = $data['UF_CRM_TASK'] ?? [];
    if (!is_array($links)) {
        return [];
    }

    $normalized = [];
    foreach ($links as $link) {
        if ($link !== null && $link !== '') {
            $normalized[] = (string) $link;
        }
    }

    return array_values(array_unique($normalized));
}

function outgoingWebhookEnsureTaskCrmLinks(?array $taskData, string $taskId, callable $restCall): ?array
{
    if (!is_array($taskData)) {
        return $taskData;
    }

    if (isset($taskData['UF_CRM_TASK']) && is_array($taskData['UF_CRM_TASK'])) {
        return $taskData;
    }

    $links = outgoingWebhookLoadTaskCrmLinks($taskId, $restCall);
    if (!empty($links)) {
        $taskData['UF_CRM_TASK'] = $links;
    }

    return $taskData;
}

function outgoingWebhookExtractDealIds(array $crmLinks): array
{
    $dealIds = [];
    foreach ($crmLinks as $link) {
        if (!is_string($link) || $link === '') {
            continue;
        }
        if (str_starts_with($link, 'D_')) {
            $dealId = substr($link, 2);
            if ($dealId !== '') {
                $dealIds[] = $dealId;
            }
            continue;
        }
        if (preg_match('~/crm/deal/details/(\d+)/~', $link, $matches)) {
            $dealIds[] = $matches[1];
        }
    }

    return array_values(array_unique($dealIds));
}

function outgoingWebhookGetTaskAttachedFiles(string $taskId, callable $restCall): array
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

function outgoingWebhookAttachFilesToTask(string $taskId, array $fileIds, callable $restCall): array
{
    $attached = [];
    $errors = [];
    $existing = outgoingWebhookGetTaskAttachedFiles($taskId, $restCall);

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
            $errors[] = ['fileId' => $fileId, 'error' => $result['error'] ?? 'unknown'];
            continue;
        }
        $attached[] = $fileId;
    }

    return [
        'attached' => $attached,
        'errors' => $errors,
    ];
}

function outgoingWebhookGetDiskFileInfo(string $fileId, callable $restCall): ?array
{
    $result = $restCall('disk.file.get', ['id' => (int) $fileId]);
    if (!is_array($result) || !empty($result['error'])) {
        return null;
    }

    $data = $result['result'] ?? null;
    return is_array($data) ? $data : null;
}

function outgoingWebhookDownloadBase64FromUrl(string $url): ?string
{
    $data = @file_get_contents($url);
    if ($data === false) {
        return null;
    }

    return base64_encode($data);
}

function outgoingWebhookGetClientEndpoint(): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $settingsPath = __DIR__ . '/../app/settings.json';
    if (!file_exists($settingsPath)) {
        $cached = null;
        return null;
    }

    $data = json_decode((string) file_get_contents($settingsPath), true);
    if (!is_array($data)) {
        $cached = null;
        return null;
    }

    $endpoint = $data['client_endpoint'] ?? null;
    if (is_string($endpoint) && $endpoint !== '') {
        $cached = $endpoint;
        return $cached;
    }

    $cached = null;
    return null;
}

function outgoingWebhookResolveAbsoluteUrl(string $url): string
{
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }

    if (!str_starts_with($url, '/')) {
        return $url;
    }

    $endpoint = outgoingWebhookGetClientEndpoint();
    if (!is_string($endpoint) || $endpoint === '') {
        return $url;
    }

    $parts = parse_url($endpoint);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? null;
    if ($host === null) {
        return $url;
    }

    return $scheme . '://' . $host . $url;
}

function outgoingWebhookBuildDealFileData(string $fileId, callable $restCall): ?array
{
    $info = outgoingWebhookGetDiskFileInfo($fileId, $restCall);
    if ($info === null) {
        return null;
    }

    $name = $info['name'] ?? ('file_' . $fileId);
    $downloadUrl = $info['downloadUrl'] ?? $info['DOWNLOAD_URL'] ?? null;
    if (!is_string($downloadUrl) || $downloadUrl === '') {
        return null;
    }

    $downloadUrl = outgoingWebhookResolveAbsoluteUrl($downloadUrl);
    $base64 = outgoingWebhookDownloadBase64FromUrl($downloadUrl);
    if ($base64 === null) {
        return null;
    }

    return [$name, $base64];
}

function outgoingWebhookGetDealFileField(string $dealId, string $field, callable $restCall): array
{
    $result = $restCall('crm.deal.get', ['id' => (int) $dealId]);
    if (!is_array($result) || !empty($result['error'])) {
        return [];
    }

    $data = $result['result'] ?? [];
    if (!is_array($data)) {
        return [];
    }

    $value = $data[$field] ?? [];
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $item) {
        if (is_array($item)) {
            $normalized[] = [
                'id' => $item['id'] ?? ($item['ID'] ?? null),
                'downloadUrl' => $item['downloadUrl'] ?? ($item['DOWNLOAD_URL'] ?? null),
                'showUrl' => $item['showUrl'] ?? ($item['SHOW_URL'] ?? null),
            ];
            continue;
        }
        if (is_scalar($item)) {
            $normalized[] = [
                'id' => (string) $item,
                'downloadUrl' => null,
                'showUrl' => null,
            ];
        }
    }

    return $normalized;
}

function outgoingWebhookBuildDealFileDataFromDealEntry(array $entry): ?array
{
    $downloadUrl = $entry['downloadUrl'] ?? null;
    if (!is_string($downloadUrl) || $downloadUrl === '') {
        return null;
    }

    $downloadUrl = outgoingWebhookResolveAbsoluteUrl($downloadUrl);
    $base64 = outgoingWebhookDownloadBase64FromUrl($downloadUrl);
    if ($base64 === null) {
        return null;
    }

    $name = null;
    $path = parse_url($downloadUrl, PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
        $basename = basename($path);
        if ($basename !== '') {
            $name = $basename;
        }
    }

    if ($name === null || $name === '') {
        $id = $entry['id'] ?? 'file';
        $name = 'deal_file_' . $id;
    }

    return [$name, $base64];
}

function outgoingWebhookUpdateDealFiles(string $dealId, string $field, array $fileDataList, callable $restCall): array
{
    $existingIds = outgoingWebhookGetDealFileField($dealId, $field, $restCall);
    $payload = [];
    $errors = [];

    foreach ($existingIds as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $fileData = outgoingWebhookBuildDealFileDataFromDealEntry($entry);
        if ($fileData === null) {
            $errors[] = ['fileId' => $entry['id'] ?? 'unknown', 'error' => 'failed_to_load_existing_file'];
            continue;
        }
        $payload[] = ['fileData' => $fileData];
    }

    foreach ($fileDataList as $item) {
        if (is_array($item) && isset($item['fileData'])) {
            $payload[] = $item;
        }
    }

    $result = $restCall('crm.deal.update', [
        'id' => (int) $dealId,
        'fields' => [
            $field => $payload,
        ],
    ]);

    if (!is_array($result) || !empty($result['error'])) {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'unknown',
            'fileErrors' => $errors,
        ];
    }

    return ['success' => true, 'fileErrors' => $errors];
}

function outgoingWebhookLogActivityFirst(array $entry): void
{
    $path = __DIR__ . '/logs/activity-first.log';
    outgoingWebhookAppendLine($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
function outgoingWebhookBuildCommentDetails(
    array $commentData,
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId,
    ?string $sourceMethod,
    ?array $taskData = null
): array {
    $meta = outgoingWebhookExtractTaskMeta($taskData);
    $resolvedCommentId = outgoingWebhookGetFirstValue(
        $commentData,
        ['ID', 'id', 'COMMENT_ID', 'MESSAGE_ID', 'messageId']
    );

    $details = [
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
        'projectId' => $meta['projectId'],
        'projectName' => $meta['projectName'],
        'crmLinks' => $meta['crmLinks'],
    ];

    $details['commentKind'] = outgoingWebhookResolveCommentKind($details['authorId'] ?? null);
    $details['activityFirst'] = outgoingWebhookEvaluateActivityFirst($details);
    return $details;
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
        'commentKind' => 'unknown',
        'message' => 'контекст не доступен в REST',
        'createdAt' => 'unknown',
        'sourceMethod' => 'fallback',
    ];
}

function outgoingWebhookResolveCommentKind($authorId): string
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

function outgoingWebhookFormatCommentDetailsRu(array $details): string
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
        outgoingWebhookNormalizeLogValue($details['projectName'] ?? 'unknown'),
        $details['projectId'] ?? 'unknown',
        $crmText,
        $details['commentId'] ?? 'unknown',
        $details['authorId'] ?? 'unknown',
        $details['commentKind'] ?? 'unknown',
        $details['createdAt'] ?? 'unknown',
        outgoingWebhookNormalizeLogValue($details['message'] ?? 'unknown'),
        $filesText,
        $activityFirst,
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
            return null;
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

    return null;
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

function outgoingWebhookExtractChatId(array $taskData): ?string
{
    $value = outgoingWebhookGetFirstValue($taskData, ['chatId', 'CHAT_ID', 'chat_id']);
    return $value !== null ? (string) $value : null;
}

function outgoingWebhookFindChatMessage(array $payload, string $messageId): ?array
{
    $listKeys = ['messages', 'list', 'items', 'result'];
    foreach ($listKeys as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            foreach ($payload[$key] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemId = outgoingWebhookGetFirstValue($item, ['id', 'ID', 'messageId', 'MESSAGE_ID']);
                if ($itemId !== null && (string) $itemId === (string) $messageId) {
                    return $item;
                }
            }
        }
    }

    return null;
}

function outgoingWebhookFetchChatMessageDetails(callable $restCall, string $chatId, string $messageId): array
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

        $message = outgoingWebhookFindChatMessage($payloadData, $messageId);
        if (is_array($message)) {
            return ['data' => $message, 'method' => $attempt['method'], 'errors' => $errors];
        }
    }

    return ['data' => null, 'method' => null, 'errors' => $errors];
}

function outgoingWebhookBuildCommentDetailsFromChat(
    array $messageData,
    string $eventType,
    ?string $requestId,
    ?string $taskId,
    ?string $commentId,
    ?string $sourceMethod,
    ?array $taskData = null
): array {
    $meta = outgoingWebhookExtractTaskMeta($taskData);
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
        'loggedAt' => outgoingWebhookNow(),
        'requestId' => $requestId ?? 'unknown',
        'eventType' => $eventType,
        'taskId' => $taskId ?? 'unknown',
        'commentId' => $commentId ?? 'unknown',
        'authorId' => outgoingWebhookGetFirstValue($messageData, ['AUTHOR_ID', 'author_id', 'authorId', 'FROM_ID', 'from_id']) ?? 'unknown',
        'message' => outgoingWebhookGetFirstValue($messageData, ['TEXT', 'text', 'MESSAGE', 'message']) ?? 'unknown',
        'createdAt' => outgoingWebhookGetFirstValue($messageData, ['DATE_CREATE', 'date_create', 'DATE', 'date']) ?? 'unknown',
        'sourceMethod' => $sourceMethod ?? 'unknown',
        'fileIds' => $fileIds,
        'projectId' => $meta['projectId'],
        'projectName' => $meta['projectName'],
        'crmLinks' => $meta['crmLinks'],
    ];

    $details['commentKind'] = outgoingWebhookResolveCommentKind($details['authorId'] ?? null);
    $details['activityFirst'] = outgoingWebhookEvaluateActivityFirst($details);
    return $details;
}

function outgoingWebhookWriteCommentEnriched(
    string $eventType,
    string $entityId,
    array $taskData,
    array $commentData,
    string $sourceMethod,
    string $rawPath,
    ?string $requestId
): void {
    $eventDir = __DIR__ . '/logs/' . $eventType;
    outgoingWebhookSafeMkdir($eventDir);

    $enriched = [
        'eventType' => $eventType,
        'entityType' => 'task',
        'entityId' => $entityId,
        'enrichedAt' => outgoingWebhookNow(),
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

    outgoingWebhookWriteJson($eventDir . '/enriched.json', $enriched);
}

function outgoingWebhookShouldWriteCommentEnriched(?string $taskId): bool
{
    if ($taskId === null || $taskId === '') {
        return false;
    }

    $setting = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_COMMENT_ENRICHED_TASK_ID');
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
