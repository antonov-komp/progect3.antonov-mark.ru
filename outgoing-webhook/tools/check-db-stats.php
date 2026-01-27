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
