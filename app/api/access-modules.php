<?php
session_start();
ob_start();
require_once __DIR__ . '/../crest.php';
require_once __DIR__ . '/../Services/AccessContextService.php';
require_once __DIR__ . '/../Services/AccessConfigService.php';
require_once __DIR__ . '/../Services/AccessModulesService.php';
require_once __DIR__ . '/../Services/AppLogger.php';
require_once __DIR__ . '/../Services/Bitrix24Client.php';
require_once __DIR__ . '/../Services/BitrixUserProfileService.php';
require_once __DIR__ . '/../Services/RequestContextService.php';
require_once __DIR__ . '/../Services/JsonResponseService.php';

$bootstrapOutput = ob_get_clean();
$contextService = new RequestContextService($_REQUEST, $_SESSION);
$contextService->getContext();

$responseService = new JsonResponseService('access-modules');
$responseService->logBootstrapOutput($bootstrapOutput);

$accessContextService = new AccessContextService($_REQUEST, $_SESSION);
$appLogger = new AppLogger();
$bitrix24Client = new Bitrix24Client();
$accessConfigService = new AccessConfigService($appLogger);
$modulesService = new AccessModulesService($appLogger);
$profileService = new BitrixUserProfileService($accessContextService, $bitrix24Client);

$configResult = $accessConfigService->getConfig();
$config = $configResult['config'];
$superAdminId = (string) ($config['super_admin_id'] ?? '');

$profile = $profileService->fetchProfile();
$currentUserId = isset($profile['user']['id']) ? (string) $profile['user']['id'] : '';
$isSuperAdmin = $currentUserId !== '' && $superAdminId !== '' && $currentUserId === $superAdminId;

$modulesResult = $modulesService->getConfig();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $responseService->send([
        'status' => 'ok',
        'error_message' => '',
        'modules' => $modulesResult['config']['modules'],
        'config_error' => $modulesResult['config_error'],
        'is_super_admin' => $isSuperAdmin,
    ]);
    return;
}

if (!$isSuperAdmin) {
    $responseService->send([
        'status' => 'error',
        'error_message' => 'Нет прав на доступ к настройкам.',
        'modules' => $modulesResult['config']['modules'],
    ]);
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

$updatedConfig = $modulesService->normalizeConfig([
    'modules' => $payload['modules'] ?? $modulesResult['config']['modules'],
]);

$saveResult = $modulesService->saveConfig($updatedConfig);
if (!$saveResult['success']) {
    $responseService->send([
        'status' => 'error',
        'error_message' => $saveResult['error_message'],
        'modules' => $saveResult['config']['modules'],
    ]);
    return;
}

$responseService->send([
    'status' => 'ok',
    'error_message' => '',
    'modules' => $saveResult['config']['modules'],
]);
