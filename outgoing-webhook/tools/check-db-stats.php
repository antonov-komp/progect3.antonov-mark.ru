<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

$config = new ConfigService();
$filesystem = new FilesystemService();
$request = new RequestService($config);
$errors = new ErrorService($filesystem, $request);

$dbPath = __DIR__ . '/../database/events.db';
$db = new DatabaseService($dbPath, $errors, true);
$pdo = $db->getConnection();

echo "=== Статистика по событиям ===\n";
$stmt = $pdo->query("SELECT event_type, COUNT(*) as count FROM events GROUP BY event_type ORDER BY count DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$row['event_type']}: {$row['count']}\n";
}

echo "\n=== Последние 10 событий ===\n";
$stmt = $pdo->query("SELECT id, event_type, entity_id, received_at FROM events ORDER BY id DESC LIMIT 10");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  ID: {$row['id']} | {$row['event_type']} | task:{$row['entity_id']} | {$row['received_at']}\n";
}

echo "\n=== Заданий в очереди ===\n";
$stmt = $pdo->query("SELECT COUNT(*) as count FROM queue_jobs");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  Всего: {$row['count']}\n";

if ($row['count'] > 0) {
    echo "\n=== Детали заданий в очереди ===\n";
    $stmt = $pdo->query("SELECT id, request_id, event_type, status, created_at FROM queue_jobs ORDER BY id DESC LIMIT 5");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  ID: {$row['id']} | {$row['event_type']} | status:{$row['status']} | {$row['created_at']}\n";
    }
}

echo "\n=== Снимки сущностей (entity_states) ===\n";
$stmt = $pdo->query("SELECT COUNT(*) as count FROM entity_states");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  Всего: {$row['count']}\n";
if ($row['count'] > 0) {
    $stmt = $pdo->query("SELECT id, entity_type, entity_id, updated_at FROM entity_states ORDER BY updated_at DESC LIMIT 5");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  ID: {$row['id']} | {$row['entity_type']}:{$row['entity_id']} | {$row['updated_at']}\n";
    }
}

echo "\n=== Изменения полей (entity_field_changes) ===\n";
$stmt = $pdo->query("SELECT COUNT(*) as count FROM entity_field_changes");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  Всего: {$row['count']}\n";
if ($row['count'] > 0) {
    $stmt = $pdo->query("SELECT id, entity_type, entity_id, event_type, changed_at FROM entity_field_changes ORDER BY id DESC LIMIT 5");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  ID: {$row['id']} | {$row['entity_type']}:{$row['entity_id']} | {$row['event_type']} | {$row['changed_at']}\n";
    }
}

echo "\n=== Детали сделок (deal_details) ===\n";
$stmt = $pdo->query("SELECT COUNT(*) as count FROM deal_details");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  Всего: {$row['count']}\n";
if ($row['count'] > 0) {
    $info = $pdo->query("PRAGMA table_info(deal_details)")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($info, 'name');
    $cols = 'id, request_id, event_type, deal_id, created_at';
    if (in_array('stage_id', $names, true)) {
        $cols .= ', stage_id, stage_title, category_id, category_title, assigned_by_id, assigned_by_name, modify_by_id, modify_by_name';
    }
    $stmt = $pdo->query("SELECT {$cols} FROM deal_details ORDER BY id DESC LIMIT 5");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $line = "  ID: {$row['id']} | {$row['event_type']} | deal:{$row['deal_id']} | {$row['created_at']}";
        if (isset($row['stage_title'])) {
            $line .= " | Стадия: {$row['stage_title']} | Отв.: " . ($row['assigned_by_name'] ?? $row['assigned_by_id'] ?? '?');
        }
        echo $line . "\n";
    }
}
