<?php
session_start();
ob_start();
require_once __DIR__ . '/../crest.php';
require_once __DIR__ . '/../Services/AccessContextService.php';
require_once __DIR__ . '/../Services/AppLogger.php';
require_once __DIR__ . '/../Services/Bitrix24Client.php';
require_once __DIR__ . '/../Services/RequestContextService.php';
require_once __DIR__ . '/../Services/JsonResponseService.php';
require_once __DIR__ . '/../Services/UserFieldService.php';

$bootstrapOutput = ob_get_clean();
$contextService = new RequestContextService($_REQUEST, $_SESSION);
$contextService->getContext();

$responseService = new JsonResponseService('user-fields');
$responseService->logBootstrapOutput($bootstrapOutput);

$accessContextService = new AccessContextService($_REQUEST, $_SESSION);
$appLogger = new AppLogger();
$bitrix24Client = new Bitrix24Client();
$userFieldService = new UserFieldService($bitrix24Client, $appLogger, $accessContextService);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $responseService->send([
        'status' => 'error',
        'error_message' => 'Метод не поддерживается.',
    ]);
    return;
}

$section = isset($_GET['section']) ? trim((string) $_GET['section']) : '';
$entityTypeId = isset($_GET['entityTypeId']) ? trim((string) $_GET['entityTypeId']) : '';

if ($section === '') {
    $responseService->send([
        'status' => 'error',
        'error_message' => 'Параметр section обязателен.',
    ]);
    return;
}

if ($section === 'sections') {
    $sections = [
        ['id' => 'deal', 'title' => 'Сделки', 'entityId' => 'CRM_DEAL'],
        ['id' => 'lead', 'title' => 'Лиды', 'entityId' => 'CRM_LEAD'],
        ['id' => 'contact', 'title' => 'Контакты', 'entityId' => 'CRM_CONTACT'],
        ['id' => 'company', 'title' => 'Компании', 'entityId' => 'CRM_COMPANY'],
    ];

    $smartTypes = $userFieldService->getSmartProcessTypes();

    $responseService->send([
        'status' => 'ok',
        'sections' => $sections,
        'smart_types' => $smartTypes,
    ]);
    return;
}

$userFields = [];
$entityTypeIdOut = null;

switch ($section) {
    case 'deal':
        $userFields = $userFieldService->getDealUserFields();
        break;
    case 'lead':
        $userFields = $userFieldService->getLeadUserFields();
        break;
    case 'contact':
        $userFields = $userFieldService->getContactUserFields();
        break;
    case 'company':
        $userFields = $userFieldService->getCompanyUserFields();
        break;
    case 'smart':
        if ($entityTypeId === '') {
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Для смарт-процессов обязателен параметр entityTypeId.',
            ]);
            return;
        }
        $userFields = $userFieldService->getSmartProcessUserFields($entityTypeId);
        $entityTypeIdOut = $entityTypeId;
        break;
    default:
        $responseService->send([
            'status' => 'error',
            'error_message' => 'Неизвестный раздел: ' . $section,
        ]);
        return;
}

$payload = [
    'status' => 'ok',
    'section' => $section,
    'entityTypeId' => $entityTypeIdOut,
    'user_fields' => $userFields,
    'total' => count($userFields),
];

$responseService->send($payload);
