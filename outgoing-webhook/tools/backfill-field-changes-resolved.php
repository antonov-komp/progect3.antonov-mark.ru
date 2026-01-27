<?php
declare(strict_types=1);

/**
 * Обратное заполнение changes_resolved для entity_field_changes (сделки).
 * Использование: php backfill-field-changes-resolved.php [--deal-id=ID] [--dry-run]
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

$options = getopt('', ['deal-id::', 'dry-run']);
$dealId = isset($options['deal-id']) ? trim((string) $options['deal-id']) : null;
$dryRun = isset($options['dry-run']);

$container = new ServiceContainer();
$config = $container->get('config');
$dbPath = $config->get('DATABASE_PATH', __DIR__ . '/../database/events.db');

if (!file_exists($dbPath)) {
    echo "БД не найдена: {$dbPath}\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$info = @$pdo->query("PRAGMA table_info(entity_field_changes)")->fetchAll(PDO::FETCH_ASSOC);
if (!$info || !in_array('changes_resolved', array_column($info, 'name'), true)) {
    echo "Колонка changes_resolved отсутствует. Выполните run-migrations.php.\n";
    exit(1);
}

$resolver = $container->get('dealFieldsResolver');
$sql = "SELECT id, entity_id, changes, changes_resolved FROM entity_field_changes WHERE entity_type = 'deal'";
$params = [];
if ($dealId !== null && $dealId !== '') {
    $sql .= " AND entity_id = :deal_id";
    $params[':deal_id'] = $dealId;
}
$sql .= " ORDER BY id";

$stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
if ($params) {
    $stmt->execute($params);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($rows as $row) {
    $resolved = $row['changes_resolved'] ?? null;
    if ($resolved !== null && $resolved !== '') {
        continue;
    }
    $ch = json_decode($row['changes'], true);
    if (!is_array($ch) || $ch === []) {
        continue;
    }
    $resolved = [];
    foreach ($ch as $field => $v) {
        $oldVal = is_array($v) ? ($v['old'] ?? null) : null;
        $newVal = is_array($v) ? ($v['new'] ?? null) : null;
        try {
            $r = $resolver->resolveChangeForField($field, $oldVal, $newVal);
            $resolved[$field] = [
                'title' => $r['title'],
                'old_display' => $r['old_display'],
                'new_display' => $r['new_display'],
            ];
        } catch (Throwable $e) {
            $resolved[$field] = [
                'title' => $field,
                'old_display' => is_array($oldVal) ? json_encode($oldVal, JSON_UNESCAPED_UNICODE) : (string) $oldVal,
                'new_display' => is_array($newVal) ? json_encode($newVal, JSON_UNESCAPED_UNICODE) : (string) $newVal,
            ];
        }
    }
    if ($dryRun) {
        echo "Dry-run: would update entity_field_changes id={$row['id']} deal={$row['entity_id']}\n";
        $updated++;
        continue;
    }
    $upd = $pdo->prepare("UPDATE entity_field_changes SET changes_resolved = :r WHERE id = :id");
    $upd->execute([':r' => json_encode($resolved, JSON_UNESCAPED_UNICODE), ':id' => $row['id']]);
    if ($upd->rowCount() > 0) {
        echo "Updated entity_field_changes id={$row['id']} deal={$row['entity_id']}\n";
        $updated++;
    }
}

echo "Готово. Обработано: {$updated}\n";
