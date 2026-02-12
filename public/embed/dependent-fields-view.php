<?php
/**
 * Handler поля-встройки «Зависимые поля».
 *
 * Bitrix24 отображает эту страницу в iframe внутри поля карточки CRM.
 * Параметры передаются через query или PLACEMENT_OPTIONS (JSON).
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
            require_once $root . '/app/Services/UserFieldService.php';
            require_once $root . '/app/Services/UserFieldTypeService.php';
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

$fields = [];
$source = 'current_entity';
$maxHeight = 200;
if ($settings !== null) {
    $fields = isset($settings['fields']) && is_array($settings['fields']) ? $settings['fields'] : [];
    $source = trim((string) ($settings['source'] ?? 'current_entity'));
    $maxHeight = (int) ($settings['maxHeight'] ?? 200);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; font-size: 13px; padding: 12px; margin: 0; color: #333; }
        .embed-fields { display: flex; flex-direction: column; gap: 8px; }
        .embed-field-row { display: flex; gap: 8px; padding: 6px 0; border-bottom: 1px solid #eee; }
        .embed-field-label { font-weight: 500; min-width: 120px; color: #666; }
        .embed-field-value { flex: 1; }
        .embed-empty { color: #666; font-size: 12px; }
        .embed-fields-wrap { overflow-y: auto; }
    </style>
</head>
<body>
<div class="embed-fields-wrap" style="max-height: <?= (int) $maxHeight ?>px;">
<div class="embed-fields">
<?php foreach ($fields as $f): ?>
    <?php
    $fname = trim((string) ($f['fieldName'] ?? ''));
    $label = trim((string) ($f['label'] ?? $fname ?: '—'));
    if ($fname === '' && $label === '') continue;
    $value = '—';
    $format = trim((string) ($f['format'] ?? 'text'));
    ?>
    <div class="embed-field-row">
        <span class="embed-field-label"><?= htmlspecialchars($label) ?>:</span>
        <span class="embed-field-value" data-field="<?= htmlspecialchars($fname) ?>" data-format="<?= htmlspecialchars($format) ?>"><?= htmlspecialchars($value) ?></span>
    </div>
<?php endforeach; ?>
</div>
</div>
<?php if (empty($fields)): ?>
<div class="embed-empty">Настройте поля в модуле «Пользовательские поля» → это поле → «Настроить».</div>
<?php else: ?>
<script>
(function() {
    var entityId = <?= json_encode($entityId) ?>;
    var entityValueId = <?= json_encode($entityValueId) ?>;
    var source = <?= json_encode($source) ?>;
    if (!entityId || !entityValueId) return;
    if (typeof BX24 === 'undefined') return;

    var method = 'crm.deal.get';
    if (entityId === 'CRM_LEAD') method = 'crm.lead.get';
    else if (entityId === 'CRM_CONTACT') method = 'crm.contact.get';
    else if (entityId === 'CRM_COMPANY') method = 'crm.company.get';

    function fillValues(data) {
        var rows = document.querySelectorAll('.embed-field-value[data-field]');
        rows.forEach(function(el) {
            var fn = el.getAttribute('data-field');
            var fmt = el.getAttribute('data-format') || 'text';
            var v = data[fn];
            if (v === undefined && data.FIELDS) v = data.FIELDS[fn];
            if (Array.isArray(v) && v[0] && v[0].VALUE) v = v[0].VALUE;
            else if (typeof v === 'object' && v && v.VALUE) v = v.VALUE;
            if (v !== undefined && v !== null) {
                if (fmt === 'phone' && typeof v === 'string') el.innerHTML = '<a href="tel:' + v.replace(/[^0-9+]/g,'') + '">' + escapeHtml(v) + '</a>';
                else if (fmt === 'email' && typeof v === 'string') el.innerHTML = '<a href="mailto:' + escapeHtml(v) + '">' + escapeHtml(v) + '</a>';
                else if (fmt === 'url' && typeof v === 'string') el.innerHTML = '<a href="' + escapeHtml(v) + '" target="_blank">' + escapeHtml(v) + '</a>';
                else el.textContent = String(v);
            }
        });
    }
    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    BX24.ready(function() {
        if (source === 'contact' && entityId !== 'CRM_CONTACT') {
            BX24.callMethod(method, { id: entityValueId }, function(r) {
                if (r.error()) return;
                var data = r.data();
                var cid = (data.CONTACT_ID || (data.CONTACT_IDS && data.CONTACT_IDS[0]) || (data.ASSOCIATED_CONTACT_ID));
                if (!cid) { fillValues({}); return; }
                BX24.callMethod('crm.contact.get', { id: cid }, function(r2) {
                    if (r2.error()) return;
                    fillValues(r2.data());
                });
            });
        } else if (source === 'company' && entityId !== 'CRM_COMPANY') {
            BX24.callMethod(method, { id: entityValueId }, function(r) {
                if (r.error()) return;
                var data = r.data();
                var cid = data.COMPANY_ID;
                if (!cid) { fillValues({}); return; }
                BX24.callMethod('crm.company.get', { id: cid }, function(r2) {
                    if (r2.error()) return;
                    fillValues(r2.data());
                });
            });
        } else {
            BX24.callMethod(method, { id: entityValueId }, function(r) {
                if (r.error()) return;
                fillValues(r.data());
            });
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
