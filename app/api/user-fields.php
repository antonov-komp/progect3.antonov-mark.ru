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
require_once __DIR__ . '/../Services/UserFieldTypeService.php';
require_once __DIR__ . '/../Services/EmbedFieldSettingsService.php';

$bootstrapOutput = ob_get_clean();
$contextService = new RequestContextService($_REQUEST, $_SESSION);
$contextService->getContext();

$responseService = new JsonResponseService('user-fields');
$responseService->logBootstrapOutput($bootstrapOutput);

$accessContextService = new AccessContextService($_REQUEST, $_SESSION);
$appLogger = new AppLogger();
$bitrix24Client = new Bitrix24Client();
$userFieldTypeService = new UserFieldTypeService($bitrix24Client, $appLogger, $accessContextService);
$userFieldService = new UserFieldService($bitrix24Client, $userFieldTypeService, $appLogger, $accessContextService);
$embedSettingsService = new EmbedFieldSettingsService();

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
    $mode = trim((string) ($postPayload['mode'] ?? 'standard'));
    $action = trim((string) ($postPayload['action'] ?? ''));
    $fields = isset($postPayload['fields']) && is_array($postPayload['fields']) ? $postPayload['fields'] : [];
    $embedFieldId = isset($postPayload['field_id']) ? (int) $postPayload['field_id'] : 0;
    $embedFieldName = trim((string) ($postPayload['field_name'] ?? ''));
    $embedSettings = isset($postPayload['settings']) && is_array($postPayload['settings']) ? $postPayload['settings'] : null;

    if ($action === 'embed_settings' && $embedFieldId > 0 && $embedSettings !== null) {
        if (!in_array($section, ['deal', 'lead', 'contact', 'company', 'smart'], true)) {
            $responseService->send(['status' => 'error', 'error_message' => 'Неверный раздел.']);
            return;
        }
        $fieldNameForHandler = $embedFieldName;
        $entityIdOverride = null;
        if ($fieldNameForHandler === '') {
            $list = match ($section) {
                'deal' => $userFieldService->getDealUserFields(),
                'lead' => $userFieldService->getLeadUserFields(),
                'contact' => $userFieldService->getContactUserFields(),
                'company' => $userFieldService->getCompanyUserFields(),
                'smart' => ($spaId = trim((string) ($postPayload['entityTypeId'] ?? $postPayload['typeId'] ?? '')) !== ''
                    ? $userFieldService->getSmartProcessUserFields($spaId) : []),
                default => [],
            };
            foreach ($list as $f) {
                $fid = (int) ($f['ID'] ?? $f['id'] ?? 0);
                if ($fid === $embedFieldId) {
                    $fieldNameForHandler = trim((string) ($f['FIELD_NAME'] ?? $f['fieldName'] ?? ''));
                    break;
                }
            }
        }
        if ($section === 'smart') {
            $spaId = trim((string) ($postPayload['entityTypeId'] ?? $postPayload['typeId'] ?? ''));
            if ($spaId !== '') {
                $entityIdOverride = ['CRM_' . $spaId, 'DYNAMIC_' . $spaId];
            }
        }
        $ok = $embedSettingsService->save($section, $embedFieldId, $embedSettings, $fieldNameForHandler !== '' ? $fieldNameForHandler : null, $entityIdOverride);
        if (!$ok) {
            $dir = dirname(__DIR__, 2) . '/data/embed-settings';
            $hint = '';
            if (!is_dir($dir)) {
                $hint = ' Создайте каталог: ' . $dir;
            } elseif (!is_writable($dir)) {
                $hint = ' Нет прав на запись в ' . $dir;
            }
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Не удалось сохранить настройки.' . $hint,
            ]);
            return;
        }
        $responseService->send(['status' => 'ok']);
        return;
    }

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

    if ($mode === 'embed') {
        if (!in_array($section, ['deal', 'lead', 'contact', 'company', 'smart'], true)) {
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Неверный раздел.',
            ]);
            return;
        }
        if ($section === 'smart' && $entityTypeId === '' && $typeId === '') {
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Для смарт-процессов укажите entityTypeId или typeId.',
            ]);
            return;
        }

        $embedTypeId = trim((string) ($postPayload['embed_type_id'] ?? ''));
        $handlerUrl = trim((string) ($postPayload['handler_url'] ?? ''));
        $userTypeId = trim((string) ($postPayload['user_type_id'] ?? ''));
        $label = trim((string) ($postPayload['label'] ?? ''));
        $fieldName = trim((string) ($postPayload['field_name'] ?? ''));
        $description = trim((string) ($postPayload['description'] ?? ''));

        if ($embedTypeId !== '') {
            $configPath = __DIR__ . '/../config/embed-types.php';
            $resolved = false;
            if (is_file($configPath)) {
                $embedTypesRaw = include $configPath;
                if (is_array($embedTypesRaw)) {
                    foreach ($embedTypesRaw as $t) {
                        if (is_array($t) && ($t['id'] ?? '') === $embedTypeId) {
                            $userTypeId = $t['user_type_id'] ?? '';
                            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                            $baseUrl = rtrim($protocol . '://' . $host, '/') . '/';
                            $handlerUrl = $baseUrl . ltrim($t['handler_path'] ?? '', '/');
                            $description = $description !== '' ? $description : ($t['description'] ?? '');
                            $resolved = true;
                            break;
                        }
                    }
                }
            }
            if (!$resolved) {
                $responseService->send([
                    'status' => 'error',
                    'error_message' => 'Неизвестный тип встройки: ' . $embedTypeId,
                ]);
                return;
            }
        }

        if ($handlerUrl === '') {
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Укажите тип встройки или URL handler\'а.',
            ]);
            return;
        }
        if (filter_var($handlerUrl, FILTER_VALIDATE_URL) === false) {
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Некорректный формат URL handler\'а.',
            ]);
            return;
        }
        if ($userTypeId === '') {
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Укажите код типа поля (user_type_id).',
            ]);
            return;
        }
        if (preg_match('/^[a-z0-9_]{1,50}$/', $userTypeId) !== 1) {
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Код типа поля: только a-z, 0-9, _, макс. 50 символов.',
            ]);
            return;
        }
        if ($label === '') {
            $responseService->send([
                'status' => 'error',
                'error_message' => 'Укажите название поля.',
            ]);
            return;
        }

        $params = [
            'handler_url' => $handlerUrl,
            'user_type_id' => $userTypeId,
            'label' => $label,
            'description' => $description,
        ];
        if ($fieldName !== '') {
            $params['field_name'] = $fieldName;
        }
        if ($section === 'smart') {
            $params['entity_type_id'] = $entityTypeId;
            $params['type_id'] = $typeId;
        }

        $fieldId = $userFieldService->addEmbedField($section, $params);

        if ($fieldId === false) {
            $apiError = $userFieldService->getLastError();
            $responseService->send([
                'status' => 'error',
                'error_message' => $apiError !== '' ? $apiError : 'Не удалось создать поле-встройку.',
            ]);
            return;
        }

        $responseService->send([
            'status' => 'ok',
            'field_id' => $fieldId,
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

$getEmbedFieldId = isset($_GET['field_id']) ? (int) $_GET['field_id'] : 0;
$getAction = trim((string) ($_GET['action'] ?? ''));

if ($getAction === 'embed_settings' && $getEmbedFieldId > 0) {
    if (!in_array($section, ['deal', 'lead', 'contact', 'company', 'smart'], true)) {
        $responseService->send(['status' => 'error', 'error_message' => 'Неверный раздел.']);
        return;
    }
    $settings = $embedSettingsService->get($section, $getEmbedFieldId);
    $responseService->send([
        'status' => 'ok',
        'settings' => $settings !== null ? $settings : (object) [],
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

    $embedTypes = [];
    $configPath = __DIR__ . '/../config/embed-types.php';
    if (is_file($configPath)) {
        $embedTypesRaw = include $configPath;
        if (is_array($embedTypesRaw)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseUrl = rtrim($protocol . '://' . $host, '/') . '/';
            foreach ($embedTypesRaw as $t) {
                if (is_array($t) && isset($t['id'], $t['user_type_id'], $t['title'], $t['handler_path'])) {
                    $embedTypes[] = [
                        'id' => $t['id'],
                        'user_type_id' => $t['user_type_id'],
                        'title' => $t['title'],
                        'description' => $t['description'] ?? '',
                        'handler_url' => $baseUrl . ltrim($t['handler_path'], '/'),
                    ];
                }
            }
        }
    }

    $responseService->send([
        'status' => 'ok',
        'sections' => $sections,
        'smart_types' => $smartTypes,
        'embed_types' => $embedTypes,
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
