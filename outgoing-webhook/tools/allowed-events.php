<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

function outgoingWebhookRestCall(string $method, array $params = []): array
{
    $client = new Bitrix24Client();
    return $client->call($method, $params);
}

// REST method: methods
// Documentation: https://context7.com/bitrix24/rest/methods
$result = outgoingWebhookRestCall('methods');
if (!empty($result['error'])) {
    outgoingWebhookLogError('REST error for methods', ['error' => $result['error']]);
    outgoingWebhookJsonResponse(500, ['error' => 'rest_error', 'details' => $result['error']]);
    exit;
}

$methods = $result['result'] ?? [];
if (!is_array($methods)) {
    outgoingWebhookLogError('Unexpected methods response');
    outgoingWebhookJsonResponse(500, ['error' => 'invalid_methods_response']);
    exit;
}

sort($methods, SORT_STRING);

$groups = [
    'event' => [],
    'crm' => [],
    'tasks' => [],
    'sonet_group' => [],
    'user' => [],
];

foreach ($methods as $method) {
    if (!is_string($method)) {
        continue;
    }
    foreach (array_keys($groups) as $group) {
        if (str_starts_with($method, $group . '.')) {
            $groups[$group][] = $method;
            break;
        }
    }
}

$report = [
    'generatedAt' => outgoingWebhookNow(),
    'groups' => $groups,
];

$logDir = __DIR__ . '/../logs/allowed-events';
outgoingWebhookSafeMkdir($logDir);

outgoingWebhookWriteJson($logDir . '/allowed-events.json', $report);
outgoingWebhookWriteJson($logDir . '/raw-methods.json', ['methods' => $methods]);

outgoingWebhookJsonResponse(200, $report);
