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

$section = isset($_GET['section']) ? trim((string) $_GET['section']) : '';
$entityTypeId = isset($_GET['entityTypeId']) ? trim((string) $_GET['entityTypeId']) : '';
$typeId = isset($_GET['typeId']) ? trim((string) $_GET['typeId']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $postPayload = [];
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $postPayload = $decoded;
        }
    }
    $section = trim((string) ($postPayload['section'] ?? $section));
    $entityTypeId = trim((string) ($postPayload['entityTypeId'] ?? $entityTypeId ?? ''));
    $typeId = trim((string) ($postPayload['typeId'] ?? $typeId ?? ''));
    $fields = isset($postPayload['fields']) && is_array($postPayload['fields']) ? $postPayload['fields'] : [];

    if ($section === '') {
        $responseService->send([
            'status' => 'error',
            'error_message' => 'Параметр section обязателен.',
        ]);
        return;
    }
    if (!in_array($section, ['deal', 'lead', 'contact', 'company', 'smart'], true)) {
        $responseService->send([
            'status' => 'error',
            'error_message' => 'Неверный раздел: ' . $section,
        ]);
        return;
    }
    if ($section === 'smart' && $entityTypeId === '' && $typeId === '') {
        $responseService->send([
            'status' => 'error',
            'error_message' => 'Для смарт-процессов обязателен entityTypeId или typeId.',
        ]);
        return;
    }
    if (empty($fields) || !isset($fields['USER_TYPE_ID'])) {
        $responseService->send([
            'status' => 'error',
            'error_message' => 'Поля fields должны содержать минимум USER_TYPE_ID.',
        ]);
        return;
    }
    $label = trim((string) ($fields['EDIT_FORM_LABEL'] ?? $fields['LABEL'] ?? ''));
    if ($label === '') {
        $responseService->send([
            'status' => 'error',
            'error_message' => 'Укажите название поля (EDIT_FORM_LABEL или LABEL).',
        ]);
        return;
    }

    $fieldId = false;
    switch ($section) {
        case 'deal':
            $fieldId = $userFieldService->addDealUserField($fields);
            break;
        case 'lead':
            $fieldId = $userFieldService->addLeadUserField($fields);
            break;
        case 'contact':
            $fieldId = $userFieldService->addContactUserField($fields);
            break;
        case 'company':
            $fieldId = $userFieldService->addCompanyUserField($fields);
            break;
        case 'smart':
            $spaId = $typeId !== '' ? $typeId : $entityTypeId;
            $fieldId = $userFieldService->addSmartProcessUserField($spaId, $fields);
            break;
    }

    if ($fieldId === false) {
        $apiError = $userFieldService->getLastError();
        $errorMessage = $apiError !== ''
            ? $apiError
            : 'Не удалось создать поле. Проверьте права CRM-администратора и корректность данных.';
        $responseService->send([
            'status' => 'error',
            'error_message' => $errorMessage,
        ]);
        return;
    }

    $responseService->send([
        'status' => 'ok',
        'field_id' => $fieldId,
    ]);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $responseService->send([
        'status' => 'error',
        'error_message' => 'Метод не поддерживается.',
    ]);
    return;
}

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
        if ($entityTypeId === '' && $typeId === '') {
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Для смарт-процессов обязателен параметр entityTypeId или typeId.',
            ]);
            return;
        }
        // ENTITY_ID для crm.userfield.list = CRM_{id}, где id — из crm.type.list (ordinal), не entityTypeId
        $spaId = $typeId !== '' ? $typeId : $entityTypeId;
        $userFields = $userFieldService->getSmartProcessUserFields($spaId);
        if ($userFields === [] && $typeId !== '' && $entityTypeId !== '' && $typeId !== $entityTypeId) {
            $userFields = $userFieldService->getSmartProcessUserFields($entityTypeId);
        }
        $entityTypeIdOut = $entityTypeId ?: $typeId;
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
