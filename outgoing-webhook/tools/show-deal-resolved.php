<?php
declare(strict_types=1);

/**
 * Показать пользовательское представление полей сделки (details_resolved).
 * Использование: php show-deal-resolved.php [--deal-id=ID] [--limit=N]
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

$options = getopt('', ['deal-id::', 'limit::']);
$dealId = isset($options['deal-id']) ? trim((string) $options['deal-id']) : null;
$limit = isset($options['limit']) ? (int) $options['limit'] : 1;

$dbPath = __DIR__ . '/../database/events.db';
if (!file_exists($dbPath)) {
    echo "БД не найдена.\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "SELECT id, deal_id, event_type, created_at, details_resolved,
        stage_id, stage_title, category_id, category_title,
        assigned_by_id, assigned_by_name, modify_by_id, modify_by_name
        FROM deal_details";
$params = [];
if ($dealId !== null && $dealId !== '') {
    $sql .= " WHERE deal_id = :deal_id";
    $params[':deal_id'] = $dealId;
}
$sql .= " ORDER BY id DESC LIMIT " . max(1, $limit);

$stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
if ($params) {
    $stmt->execute($params);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    echo "--- deal_details #" . $row['id'] . " | deal " . $row['deal_id'] . " | " . $row['event_type'] . " | " . $row['created_at'] . " ---\n";
    if (isset($row['stage_id']) || isset($row['assigned_by_id'])) {
        echo "  [Ключевые поля] ";
        $k = [];
        if (($row['stage_id'] ?? '') !== '') {
            $k[] = "Стадия: " . ($row['stage_title'] ?? $row['stage_id']) . " (" . ($row['stage_id'] ?? '') . ")";
        }
        if (($row['category_id'] ?? '') !== '') {
            $k[] = "Воронка: " . ($row['category_title'] ?? $row['category_id']) . " (" . ($row['category_id'] ?? '') . ")";
        }
        if (($row['assigned_by_id'] ?? '') !== '') {
            $k[] = "Ответственный: " . ($row['assigned_by_name'] ?? '') . " (ID " . ($row['assigned_by_id'] ?? '') . ")";
        }
        if (($row['modify_by_id'] ?? '') !== '') {
            $k[] = "Кто изменил: " . ($row['modify_by_name'] ?? '') . " (ID " . ($row['modify_by_id'] ?? '') . ")";
        }
        echo implode(" | ", $k) . "\n";
    }
    $resolved = json_decode($row['details_resolved'] ?? '[]', true);
    if (!is_array($resolved)) {
        echo "  (нет details_resolved)\n\n";
        continue;
    }
    foreach ($resolved as $f) {
        $title = $f['title'] ?? $f['code'] ?? '?';
        $type = $f['type'] ?? '?';
        $display = $f['display'] ?? '';
        if ($display === '' && isset($f['raw']) && $f['raw'] !== null && $f['raw'] !== '') {
            $display = is_array($f['raw']) ? json_encode($f['raw'], JSON_UNESCAPED_UNICODE) : (string) $f['raw'];
        }
        if ((string) $display === '') {
            continue;
        }
        echo "  " . $title . " (" . $type . "): " . $display . "\n";
    }
    echo "\n";
}
