<?php
session_start();
ob_start();
require_once __DIR__ . '/../crest.php';
require_once __DIR__ . '/../Services/AccessContextService.php';
require_once __DIR__ . '/../Services/AccessConfigService.php';
require_once __DIR__ . '/../Services/AppLogger.php';
require_once __DIR__ . '/../Services/Bitrix24Client.php';
require_once __DIR__ . '/../Services/BitrixUserProfileService.php';
require_once __DIR__ . '/../Services/GreetingComposerService.php';
require_once __DIR__ . '/../Services/UserGreetingService.php';
require_once __DIR__ . '/../Services/AccessControlService.php';
require_once __DIR__ . '/../Services/RequestContextService.php';
require_once __DIR__ . '/../Services/JsonResponseService.php';
$bootstrapOutput = ob_get_clean();
$contextService = new RequestContextService($_REQUEST, $_SESSION);
$contextService->getContext();

$responseService = new JsonResponseService('app-ui-state');
$responseService->logBootstrapOutput($bootstrapOutput);

$accessContextService = new AccessContextService($_REQUEST, $_SESSION);
$appLogger = new AppLogger();
$bitrix24Client = new Bitrix24Client();
$accessConfigService = new AccessConfigService($appLogger);
$profileService = new BitrixUserProfileService($accessContextService, $bitrix24Client);
$greetingComposer = new GreetingComposerService();
$greetingService = new UserGreetingService($accessContextService, $profileService, $greetingComposer, $appLogger);
$accessControl = new AccessControlService($accessContextService, $appLogger, $profileService, $accessConfigService);
$accessDecision = $accessControl->evaluateAccess();

$response = [
    'allowed' => $responseService->normalizeBool($accessDecision['allowed'] ?? false),
    'deny_message' => $responseService->normalizeString($accessDecision['message'] ?? 'Доступ ограничен.'),
    'greeting' => '',
    'context_message' => '',
    'access' => [
        'context' => $responseService->normalizeString($accessDecision['context'] ?? 'unknown'),
        'is_embedded' => $responseService->normalizeBool($accessDecision['is_embedded'] ?? false),
        'is_super_admin' => $responseService->normalizeBool($accessDecision['is_super_admin'] ?? false),
        'decision' => $responseService->normalizeString($accessDecision['decision'] ?? 'deny'),
        'reason' => $responseService->normalizeString($accessDecision['reason'] ?? 'unknown'),
    ],
    'auth' => [
        'source' => 'app_token',
    ],
    'user' => [
        'id' => '',
        'name' => '',
        'last_name' => '',
        'is_admin' => null,
        'department' => '',
        'department_ids' => [],
    ],
    'status' => 'ok',
    'error_message' => '',
];

if (!$response['allowed']) {
    $responseService->send($response);
    return;
}

try {
    $uiState = $greetingService->getUiState($accessDecision);
    $response['greeting'] = $responseService->normalizeString($uiState['greeting'] ?? '');
    $response['context_message'] = $responseService->normalizeString($uiState['context_message'] ?? '');
    $auth = $uiState['auth'] ?? [];
    $response['auth'] = [
        'source' => $responseService->normalizeString($auth['source'] ?? 'app_token', 'app_token'),
    ];

    $user = $uiState['user'] ?? [];
    $response['user'] = [
        'id' => $responseService->normalizeString($user['id'] ?? ''),
        'name' => $responseService->normalizeString($user['name'] ?? ''),
        'last_name' => $responseService->normalizeString($user['last_name'] ?? ''),
        'is_admin' => $responseService->normalizeOptionalBool($user['is_admin'] ?? null),
        'department' => $responseService->normalizeString($user['department'] ?? ''),
        'department_ids' => is_array($user['department_ids'] ?? null) ? $user['department_ids'] : [],
    ];

    $response['status'] = ($uiState['status'] ?? 'ok') === 'error' ? 'error' : 'ok';
    if ($response['status'] === 'error') {
        $response['error_message'] = 'Не удалось загрузить данные приложения.';
    }
} catch (Throwable $exception) {
    $response['status'] = 'error';
    $response['error_message'] = 'Не удалось загрузить данные приложения.';
    $responseService->logError('ui-state exception: ' . $exception->getMessage());
}

$responseService->send($response);
