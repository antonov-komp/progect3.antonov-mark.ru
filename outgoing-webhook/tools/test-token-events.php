<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function testTokenEventsReadEventsFromDocs(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $content = (string) file_get_contents($path);
    if ($content === '') {
        return [];
    }
    preg_match_all('/\b(ON[A-Z0-9_]+|SONET_GROUP_[A-Z_]+)\b/', $content, $matches);
    $events = $matches[1] ?? [];
    $events = array_values(array_unique(array_filter($events, 'is_string')));
    sort($events, SORT_STRING);

    return $events;
}

function testTokenEventsRequest(string $endpoint, array $payload, int $timeoutSeconds): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['status' => 0, 'error' => 'json_encode_failed', 'body' => null];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'error' => $error !== '' ? $error : null,
            'body' => is_string($response) ? $response : null,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => $timeoutSeconds,
        ],
    ]);
    $response = @file_get_contents($endpoint, false, $context);
    $status = 0;
    if (is_array($http_response_header ?? null)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $headerLine, $m)) {
                $status = (int) $m[1];
                break;
            }
        }
    }

    return [
        'status' => $status,
        'error' => $response === false ? 'request_failed' : null,
        'body' => is_string($response) ? $response : null,
    ];
}

function testTokenEventsSnapshotFile(string $path): array
{
    if (!file_exists($path)) {
        return ['exists' => false, 'size' => 0, 'content' => null];
    }
    $content = (string) file_get_contents($path);
    return ['exists' => true, 'size' => strlen($content), 'content' => $content];
}

function testTokenEventsRestoreFile(string $path, array $snapshot): void
{
    if (!($snapshot['exists'] ?? false)) {
        if (file_exists($path)) {
            @unlink($path);
        }
        return;
    }

    if (isset($snapshot['content']) && is_string($snapshot['content'])) {
        file_put_contents($path, $snapshot['content']);
        return;
    }
}

function testTokenEventsTruncateFile(string $path, int $size): void
{
    if (!file_exists($path)) {
        return;
    }
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return;
    }
    if ($size >= 0) {
        ftruncate($handle, $size);
    }
    fclose($handle);
}

function testTokenEventsListFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return [];
    }
    $files = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_file($path)) {
            $files[] = $path;
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

$options = getopt('', ['endpoint::', 'event::', 'events::', 'timeout::', 'report::', 'cleanup', 'cleanup-logs', 'deal-id::']);
$endpoint = $options['endpoint'] ?? 'http://localhost/outgoing-webhook/index.php';
$dealId = isset($options['deal-id']) ? trim((string) $options['deal-id']) : null;
$timeout = isset($options['timeout']) ? (int) $options['timeout'] : 10;
if ($timeout <= 0) {
    $timeout = 10;
}
$cleanup = array_key_exists('cleanup', $options);
$cleanupLogs = array_key_exists('cleanup-logs', $options);

$token = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN');
if ($token === null || $token === '') {
    outgoingWebhookJsonResponse(500, ['error' => 'missing_token']);
    exit(1);
}

$events = [];
if (isset($options['event'])) {
    $events = is_array($options['event']) ? $options['event'] : [$options['event']];
} elseif (isset($options['events'])) {
    $events = is_array($options['events']) ? $options['events'] : explode(',', (string) $options['events']);
} else {
    $events = testTokenEventsReadEventsFromDocs(
        dirname(__DIR__, 2) . '/DOCS/OUTGOING-EVENTS/02-registered-events.md'
    );
}

$events = array_values(array_unique(array_filter(array_map('trim', $events))));
if (empty($events)) {
    outgoingWebhookJsonResponse(400, ['error' => 'no_events']);
    exit(1);
}

$report = [
    'generatedAt' => outgoingWebhookNow(),
    'endpoint' => $endpoint,
    'eventsCount' => count($events),
    'cleanup' => $cleanup,
    'cleanupLogs' => $cleanupLogs,
    'results' => [],
];

$snapshots = [];
$queueDir = dirname(__DIR__) . '/queue/pending';
$queueBefore = $cleanup ? testTokenEventsListFiles($queueDir) : [];

foreach ($events as $event) {
    if ($cleanupLogs) {
        $eventDir = dirname(__DIR__) . '/logs/' . $event;
        $rawPath = $eventDir . '/raw.json';
        $eventLogPath = $eventDir . '/event.log';
        $snapshots[$event] = [
            'raw' => testTokenEventsSnapshotFile($rawPath),
            'eventLog' => testTokenEventsSnapshotFile($eventLogPath),
        ];
    }

    $data = [];
    if ($dealId !== null && $dealId !== '' && str_starts_with($event, 'ONCRMDEAL')) {
        $data = ['FIELDS' => ['ID' => $dealId]];
    }
    $payload = [
        'event' => $event,
        'event_handler_id' => 'test-handler',
        'token' => $token,
        'auth' => [
            'application_token' => $token,
            'member_id' => 'test-member',
        ],
        'data' => $data,
    ];
    $response = testTokenEventsRequest($endpoint, $payload, $timeout);
    $report['results'][] = [
        'event' => $event,
        'status' => $response['status'],
        'error' => $response['error'],
    ];
}

if ($cleanup) {
    $queueAfter = testTokenEventsListFiles($queueDir);
    $newQueueFiles = array_diff($queueAfter, $queueBefore);
    foreach ($newQueueFiles as $file) {
        @unlink($file);
    }
}

if ($cleanupLogs) {
    foreach ($snapshots as $event => $snapshot) {
        $eventDir = dirname(__DIR__) . '/logs/' . $event;
        $rawPath = $eventDir . '/raw.json';
        $eventLogPath = $eventDir . '/event.log';

        testTokenEventsRestoreFile($rawPath, $snapshot['raw'] ?? []);
        $eventLogSnapshot = $snapshot['eventLog'] ?? ['exists' => false, 'size' => 0];
        if (!($eventLogSnapshot['exists'] ?? false)) {
            if (file_exists($eventLogPath)) {
                @unlink($eventLogPath);
            }
        } else {
            $size = (int) ($eventLogSnapshot['size'] ?? 0);
            testTokenEventsTruncateFile($eventLogPath, $size);
        }
    }
}

$has404 = false;
$has403 = false;
foreach ($report['results'] as $r) {
    $s = isset($r['status']) ? (int) $r['status'] : 0;
    if ($s === 404) {
        $has404 = true;
    }
    if ($s === 403) {
        $has403 = true;
    }
}
if ($has404) {
    $report['hint'] = '404: endpoint not found. Use --endpoint with your webhook URL. If docroot is public/, use https://your-domain/outgoing-webhook/index.php (no /public/ in path).';
}
if ($has403 && !$has404) {
    $report['hint'] = '403: invalid_token or ip_not_allowed. Check OUTGOING_WEBHOOK_TOKEN in config and OUTGOING_WEBHOOK_ALLOWED_IPS if set.';
}

if (isset($options['report']) && is_string($options['report']) && $options['report'] !== '') {
    $reportPath = $options['report'];
    outgoingWebhookSafeMkdir(dirname($reportPath));
    outgoingWebhookWriteJson($reportPath, $report);
}

outgoingWebhookJsonResponse(200, $report);
