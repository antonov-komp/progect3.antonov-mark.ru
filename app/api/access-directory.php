<?php
session_start();
ob_start();
require_once __DIR__ . '/../crest.php';
require_once __DIR__ . '/../Services/AccessContextService.php';
require_once __DIR__ . '/../Services/AccessConfigService.php';
require_once __DIR__ . '/../Services/AccessDirectoryService.php';
require_once __DIR__ . '/../Services/AppLogger.php';
require_once __DIR__ . '/../Services/Bitrix24Client.php';
require_once __DIR__ . '/../Services/BitrixUserProfileService.php';
require_once __DIR__ . '/../Services/RequestContextService.php';
require_once __DIR__ . '/../Services/JsonResponseService.php';

$bootstrapOutput = ob_get_clean();
$contextService = new RequestContextService($_REQUEST, $_SESSION);
$contextService->getContext();

$responseService = new JsonResponseService('access-directory');
$responseService->logBootstrapOutput($bootstrapOutput);

$accessContextService = new AccessContextService($_REQUEST, $_SESSION);
$appLogger = new AppLogger();
$bitrix24Client = new Bitrix24Client();
$configService = new AccessConfigService($appLogger);
$profileService = new BitrixUserProfileService($accessContextService, $bitrix24Client);
$directoryService = new AccessDirectoryService($accessContextService, $bitrix24Client, $appLogger);

$configResult = $configService->getConfig();
$config = $configResult['config'];
$superAdminId = (string) ($config['super_admin_id'] ?? '');

$profile = $profileService->fetchProfile();
$currentUserId = isset($profile['user']['id']) ? (string) $profile['user']['id'] : '';
$isSuperAdmin = $currentUserId !== '' && $superAdminId !== '' && $currentUserId === $superAdminId;

if (!$isSuperAdmin) {
    $responseService->send([
        'status' => 'error',
        'error_message' => 'Нет прав на доступ к справочнику.',
        'users' => [],
        'departments' => [],
    ]);
    return;
}

$usersResult = $directoryService->fetchUsers();
$departmentsResult = $directoryService->fetchDepartments();

$responseService->send([
    'status' => ($usersResult['status'] === 'ok' && $departmentsResult['status'] === 'ok') ? 'ok' : 'error',
    'error_message' => $usersResult['status'] === 'error' ? $usersResult['message'] : $departmentsResult['message'],
    'users' => $usersResult['items'],
    'departments' => $departmentsResult['items'],
]);
