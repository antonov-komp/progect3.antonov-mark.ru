<?php
/**
 * Handler поля-встройки «С кнопками».
 *
 * Bitrix24 отображает эту страницу в iframe внутри поля карточки CRM.
 * Параметры передаются через query (ENTITY_ID, FIELD_NAME, ENTITY_VALUE_ID)
 * или через PLACEMENT_OPTIONS (JSON).
 *
 * @see https://apidocs.bitrix24.com/api-reference/widgets/user-field/userfieldtype-add.html
 */
session_start();
header('Content-Type: text/html; charset=utf-8');

$entityId = trim((string) ($_GET['ENTITY_ID'] ?? $_REQUEST['ENTITY_ID'] ?? ''));
$fieldName = trim((string) ($_GET['FIELD_NAME'] ?? $_REQUEST['FIELD_NAME'] ?? ''));
$entityValueId = trim((string) ($_GET['ENTITY_VALUE_ID'] ?? $_REQUEST['ENTITY_VALUE_ID'] ?? ''));

// Bitrix24 передаёт PLACEMENT_OPTIONS как JSON в query (или base64)
$placementOpts = $_GET['PLACEMENT_OPTIONS'] ?? $_REQUEST['PLACEMENT_OPTIONS'] ?? '';
if ($placementOpts !== '' && ($entityId === '' || $fieldName === '' || $entityValueId === '')) {
    $raw = is_string($placementOpts) ? $placementOpts : json_encode($placementOpts);
    $opts = json_decode($raw, true);
    if ($opts === null && $raw !== '') {
        $decoded = base64_decode($raw, true);
        if ($decoded !== false) $opts = json_decode($decoded, true);
    }
    if (is_array($opts)) {
        if ($entityId === '') $entityId = trim((string) ($opts['ENTITY_ID'] ?? ''));
        if ($fieldName === '') $fieldName = trim((string) ($opts['FIELD_NAME'] ?? ''));
        if ($entityValueId === '') $entityValueId = trim((string) ($opts['ENTITY_VALUE_ID'] ?? ''));
    }
}

$settings = null;
if ($entityId !== '' && $fieldName !== '') {
    require_once dirname(__DIR__, 2) . '/app/Services/EmbedFieldSettingsService.php';
    $svc = new EmbedFieldSettingsService();

    $resolver = null;
    $root = dirname(__DIR__, 2);
    if (is_file($root . '/app/crest.php')) {
        $resolver = function (string $eid, string $fname) use ($root): ?array {
            require_once $root . '/app/crest.php';
            require_once $root . '/app/Services/AccessContextService.php';
            require_once $root . '/app/Services/AppLogger.php';
            require_once $root . '/app/Services/Bitrix24Client.php';
            require_once $root . '/app/Services/UserFieldTypeService.php';
            require_once $root . '/app/Services/UserFieldService.php';
            $section = match ($eid) {
                'CRM_DEAL' => 'deal', 'CRM_LEAD' => 'lead', 'CRM_CONTACT' => 'contact', 'CRM_COMPANY' => 'company',
                default => null,
            };
            $spaId = null;
            if ($section === null && preg_match('/^(?:CRM_|DYNAMIC_)(\d+)$/', $eid, $m)) {
                $spaId = $m[1];
            }
            if ($section === null && $spaId === null) return null;
            $access = new AccessContextService($_REQUEST, $_SESSION);
            $logger = new AppLogger();
            $client = new Bitrix24Client();
            $typeSvc = new UserFieldTypeService($client, $logger, $access);
            $fieldSvc = new UserFieldService($client, $typeSvc, $logger, $access);
            $list = match (true) {
                $section === 'deal' => $fieldSvc->getDealUserFields(),
                $section === 'lead' => $fieldSvc->getLeadUserFields(),
                $section === 'contact' => $fieldSvc->getContactUserFields(),
                $section === 'company' => $fieldSvc->getCompanyUserFields(),
                $spaId !== null => $fieldSvc->getSmartProcessUserFields($spaId),
                default => [],
            };
            foreach ($list as $f) {
                $fn = trim((string) ($f['FIELD_NAME'] ?? $f['fieldName'] ?? ''));
                if ($fn === $fname) {
                    $fid = (int) ($f['ID'] ?? $f['id'] ?? 0);
                    return $fid > 0 ? [$section, $fid] : null;
                }
            }
            return null;
        };
    }
    $settings = $svc->getByEntityField($entityId, $fieldName, $resolver);
}

$buttons = [];
if ($settings !== null && isset($settings['buttons']) && is_array($settings['buttons'])) {
    $buttons = $settings['buttons'];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; font-size: 13px; padding: 12px; margin: 0; color: #333; }
        .embed-buttons { display: flex; flex-wrap: wrap; gap: 8px; }
        .embed-btn { padding: 8px 14px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
        .embed-btn--primary { background: #007bff; color: white; }
        .embed-btn--primary:hover { background: #0056b3; }
        .embed-btn--secondary { background: #6c757d; color: white; }
        .embed-btn--secondary:hover { background: #545b62; }
        .embed-btn--danger { background: #dc3545; color: white; }
        .embed-btn--danger:hover { background: #c82333; }
        .embed-empty { color: #666; font-size: 12px; }
    </style>
</head>
<body>
<div class="embed-buttons">
<?php foreach ($buttons as $btn): ?>
    <?php
    $text = trim((string) ($btn['text'] ?? ''));
    if ($text === '') continue;
    $action = trim((string) ($btn['action'] ?? 'open_url'));
    $style = trim((string) ($btn['style'] ?? 'primary'));
    $href = '#';
    $onclick = '';
    if ($action === 'open_url') {
        $url = trim((string) ($btn['url'] ?? ''));
        if ($url !== '') { $href = $url; }
    } elseif ($action === 'open_phone') {
        $phone = trim((string) ($btn['phone'] ?? $btn['url'] ?? ''));
        if ($phone !== '') {
            $href = str_starts_with($phone, 'tel:') ? $phone : 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
        }
    } elseif ($action === 'open_email') {
        $email = trim((string) ($btn['email'] ?? $btn['url'] ?? ''));
        if ($email !== '') {
            $href = str_starts_with($email, 'mailto:') ? $email : 'mailto:' . $email;
        }
    }
    $class = 'embed-btn embed-btn--' . (in_array($style, ['primary', 'secondary', 'danger'], true) ? $style : 'primary');
    ?>
    <a href="<?= htmlspecialchars($href) ?>" class="<?= htmlspecialchars($class) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($text) ?></a>
<?php endforeach; ?>
</div>
<?php if (empty($buttons) || array_filter($buttons, fn($b) => trim((string)($b['text'] ?? '')) !== '') === []): ?>
<div class="embed-empty">Настройте кнопки в модуле «Пользовательские поля» → это поле → «Настроить».</div>
<?php endif; ?>
</body>
</html>
