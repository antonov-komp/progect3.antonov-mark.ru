<?php
declare(strict_types=1);

/**
 * Что попало в БД по сделкам: события, entity_field_changes, deal_details.
 * Использование: php check-deal-changes.php [--deal-id=ID]
 */

$options = getopt('', ['deal-id::']);
$dealId = isset($options['deal-id']) ? trim((string) $options['deal-id']) : null;

$dbPath = __DIR__ . '/../database/events.db';
if (!file_exists($dbPath)) {
    echo "БД не найдена.\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$dealFilter = $dealId ? " AND entity_id = " . $pdo->quote($dealId) : "";

echo "=== Последние события (сделки) ===\n";
$sql = "SELECT id, request_id, event_type, entity_id, received_at FROM events WHERE event_type IN ('ONCRMDEALADD','ONCRMDEALUPDATE')" . $dealFilter . " ORDER BY id DESC LIMIT 8";
foreach ($pdo->query($sql) as $r) {
    echo "  " . $r['id'] . " | " . $r['event_type'] . " | deal " . $r['entity_id'] . " | " . $r['received_at'] . "\n";
}

echo "\n=== Изменения полей (entity_field_changes) ===\n";
$cols = 'id, entity_id, event_type, changed_at, changes';
$info = @$pdo->query("PRAGMA table_info(entity_field_changes)")->fetchAll(PDO::FETCH_ASSOC);
if ($info && in_array('changes_resolved', array_column($info, 'name'), true)) {
    $cols .= ', changes_resolved';
}
$sql = "SELECT {$cols} FROM entity_field_changes WHERE entity_type = 'deal'" . ($dealId ? " AND entity_id = " . $pdo->quote($dealId) : "") . " ORDER BY id DESC LIMIT 10";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  ID " . $r['id'] . " | deal " . $r['entity_id'] . " | " . $r['event_type'] . " | " . $r['changed_at'] . "\n";
    $resolved = isset($r['changes_resolved']) && $r['changes_resolved'] !== null && $r['changes_resolved'] !== ''
        ? json_decode($r['changes_resolved'], true) : null;
    $ch = json_decode($r['changes'], true);
    if (is_array($ch)) {
        foreach ($ch as $k => $v) {
            $oldR = '?';
            $newR = '?';
            if (is_array($resolved) && isset($resolved[$k]) && is_array($resolved[$k])) {
                $rr = $resolved[$k];
                $oldR = $rr['old_display'] ?? '';
                $newR = $rr['new_display'] ?? '';
                if ($oldR === '') { $oldR = '—'; }
                if ($newR === '') { $newR = '—'; }
                $title = $rr['title'] ?? $k;
                echo "    " . $title . " (" . $k . "): [" . $oldR . "] -> [" . $newR . "]\n";
            } else {
                $old = is_array($v) ? ($v['old'] ?? '?') : '?';
                $new = is_array($v) ? ($v['new'] ?? '?') : '?';
                if (is_array($old)) { $old = json_encode($old); }
                if (is_array($new)) { $new = json_encode($new); }
                echo "    " . $k . ": [" . $old . "] -> [" . $new . "]\n";
            }
        }
    }
    echo "\n";
}
if (empty($rows)) {
    echo "  Записей нет.\n";
}

echo "=== Последние deal_details ===\n";
$sql = "SELECT id, deal_id, event_type, created_at FROM deal_details" . ($dealId ? " WHERE deal_id = " . $pdo->quote($dealId) : "") . " ORDER BY id DESC LIMIT 5";
foreach ($pdo->query($sql) as $r) {
    echo "  " . $r['id'] . " | " . $r['event_type'] . " | deal " . $r['deal_id'] . " | " . $r['created_at'] . "\n";
}
