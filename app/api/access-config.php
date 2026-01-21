<?php
session_start();
ob_start();
require_once __DIR__ . '/../crest.php';
require_once __DIR__ . '/../Services/AccessContextService.php';
require_once __DIR__ . '/../Services/AccessConfigService.php';
require_once __DIR__ . '/../Services/AppLogger.php';
require_once __DIR__ . '/../Services/Bitrix24Client.php';
require_once __DIR__ . '/../Services/BitrixUserProfileService.php';
require_once __DIR__ . '/../Services/RequestContextService.php';
require_once __DIR__ . '/../Services/JsonResponseService.php';

$bootstrapOutput = ob_get_clean();
$contextService = new RequestContextService($_REQUEST, $_SESSION);
$contextService->getContext();

$responseService = new JsonResponseService('access-config');
$responseService->logBootstrapOutput($bootstrapOutput);

$accessContextService = new AccessContextService($_REQUEST, $_SESSION);
$appLogger = new AppLogger();
$bitrix24Client = new Bitrix24Client();
$configService = new AccessConfigService($appLogger);
$profileService = new BitrixUserProfileService($accessContextService, $bitrix24Client);

$configResult = $configService->getConfig();
$config = $configResult['config'];
$configError = $configResult['config_error'];

$profile = $profileService->fetchProfile();
$currentUserId = isset($profile['user']['id']) ? (string) $profile['user']['id'] : '';
$superAdminId = (string) ($config['super_admin_id'] ?? '');
$isSuperAdmin = $currentUserId !== '' && $superAdminId !== '' && $currentUserId === $superAdminId;

$response = [
    'status' => 'ok',
    'error_message' => '',
    'config' => $config,
    'super_admin' => $profileService->fetchUserById($superAdminId),
    'is_super_admin' => $isSuperAdmin,
    'config_error' => $configError,
];

if (!$isSuperAdmin) {
    $response['status'] = 'error';
    $response['error_message'] = 'Нет прав на доступ к настройкам.';
    $responseService->send($response);
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $responseService->send($response);
    return;
}

$payload = [];
$rawBody = file_get_contents('php://input');
if (is_string($rawBody) && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if (!is_array($payload)) {
    $payload = [];
}

$updatedConfig = $configService->normalizeConfig([
    'global_enabled' => $payload['global_enabled'] ?? $config['global_enabled'],
    'deny_direct' => $payload['deny_direct'] ?? $config['deny_direct'],
    'super_admin_id' => $config['super_admin_id'],
    'allowed_users' => $payload['allowed_users'] ?? $config['allowed_users'],
    'allowed_departments' => $payload['allowed_departments'] ?? $config['allowed_departments'],
]);

$saveResult = $configService->saveConfig($updatedConfig);
if (!$saveResult['success']) {
    $response['status'] = 'error';
    $response['error_message'] = $saveResult['error_message'];
    $response['config'] = $saveResult['config'];
    $responseService->send($response);
    return;
}

$response['config'] = $saveResult['config'];
$responseService->send($response);
