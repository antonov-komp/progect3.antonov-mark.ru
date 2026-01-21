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
        if (isset($data['FIELDS']['ID'])) {
            return (string) $data['FIELDS']['ID'];
        }
        if (isset($data['ID'])) {
            return (string) $data['ID'];
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
