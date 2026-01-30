<?php
declare(strict_types=1);

/**
 * Применение миграций к БД.
 * Использование: php run-migrations.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

$container = new ServiceContainer();
$config = $container->get('config');
$dbPath = $config->get('DATABASE_PATH', __DIR__ . '/../database/events.db');

if (!file_exists($dbPath)) {
    echo "БД не найдена: {$dbPath}. Сначала выполните init-database.php.\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$migrationsDir = __DIR__ . '/../database/migrations';
if (!is_dir($migrationsDir)) {
    echo "Нет папки migrations.\n";
    exit(0);
}

$stmt = $pdo->query("PRAGMA table_info(deal_details)");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'name');

if (!in_array('details_resolved', $colNames, true)) {
    $sql = file_get_contents($migrationsDir . '/001_add_deal_details_resolved.sql');
    $pdo->exec(trim($sql));
    echo "✓ Миграция 001_add_deal_details_resolved применена.\n";
}

if (!in_array('stage_id', $colNames, true)) {
    $sql = file_get_contents($migrationsDir . '/002_add_deal_details_key_columns.sql');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '' && stripos($stmt, 'ALTER TABLE') === 0) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'duplicate column') === false) {
                    throw $e;
                }
            }
        }
    }
    echo "✓ Миграция 002_add_deal_details_key_columns применена.\n";
}
$stmt = $pdo->query("PRAGMA table_info(deal_details)");
$colNames = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('stage_id', $colNames, true)) {
    $pdo->exec('ALTER TABLE deal_details ADD COLUMN stage_id TEXT');
    echo "✓ Добавлена отсутствующая колонка stage_id.\n";
}

try {
    $stmt = $pdo->query("PRAGMA table_info(entity_field_changes)");
    $efcCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
} catch (Throwable $e) {
    $efcCols = [];
}
if ($efcCols !== [] && !in_array('changes_resolved', $efcCols, true)) {
    $pdo->exec('ALTER TABLE entity_field_changes ADD COLUMN changes_resolved TEXT');
    echo "✓ Миграция 003_add_entity_field_changes_resolved применена.\n";
}

$stmt = $pdo->query("PRAGMA table_info(activity_first_metrics)");
$afmCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('activity_type', $afmCols, true)) {
    $pdo->exec('ALTER TABLE activity_first_metrics ADD COLUMN activity_type TEXT DEFAULT \'\'');
    echo "✓ Миграция 004: добавлена колонка activity_type.\n";
}
if (!in_array('deal_ids', $afmCols, true)) {
    $pdo->exec('ALTER TABLE activity_first_metrics ADD COLUMN deal_ids TEXT DEFAULT \'\'');
    echo "✓ Миграция 004: добавлена колонка deal_ids.\n";
}
if (!in_array('result_full', $afmCols, true)) {
    $pdo->exec('ALTER TABLE activity_first_metrics ADD COLUMN result_full TEXT');
    echo "✓ Миграция 004: добавлена колонка result_full.\n";
}
if (!in_array('file_name', $afmCols, true)) {
    $pdo->exec('ALTER TABLE activity_first_metrics ADD COLUMN file_name TEXT DEFAULT \'\'');
    echo "✓ Миграция 005: добавлена колонка file_name.\n";
}
if (!in_array('file_size', $afmCols, true)) {
    $pdo->exec('ALTER TABLE activity_first_metrics ADD COLUMN file_size INTEGER');
    echo "✓ Миграция 005: добавлена колонка file_size.\n";
}
if (!in_array('author_id', $afmCols, true)) {
    $pdo->exec('ALTER TABLE activity_first_metrics ADD COLUMN author_id TEXT DEFAULT \'\'');
    echo "✓ Миграция 006: добавлена колонка author_id.\n";
}
if (!in_array('author_name', $afmCols, true)) {
    $pdo->exec('ALTER TABLE activity_first_metrics ADD COLUMN author_name TEXT DEFAULT \'\'');
    echo "✓ Миграция 006: добавлена колонка author_name.\n";
}
if (!in_array('comment_text', $afmCols, true)) {
    $pdo->exec('ALTER TABLE activity_first_metrics ADD COLUMN comment_text TEXT DEFAULT \'\'');
    echo "✓ Миграция 006: добавлена колонка comment_text.\n";
}

echo "Готово.\n";
