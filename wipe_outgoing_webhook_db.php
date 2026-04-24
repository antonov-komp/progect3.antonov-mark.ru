<?php
declare(strict_types=1);

$baseDir = __DIR__;
$dbPath = $baseDir . '/outgoing-webhook/database/events.db';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "❌ pdo_sqlite extension is not loaded\n");
    exit(1);
}

if (!is_file($dbPath)) {
    fwrite(STDERR, "❌ DB file not found: {$dbPath}\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "== Wiping data in DB (structure preserved) ==\n";
    echo "DB: {$dbPath}\n";

    // 1) Получаем список пользовательских таблиц
    $tables = $pdo->query("
        SELECT name
        FROM sqlite_master
        WHERE type='table' AND name NOT LIKE 'sqlite_%'
    ")->fetchAll(PDO::FETCH_COLUMN);

    // 2) Чистим данные в транзакции
    $pdo->exec("PRAGMA foreign_keys = OFF");
    $pdo->exec("BEGIN IMMEDIATE");

    foreach ($tables as $table) {
        $safeTable = str_replace('"', '""', (string)$table);
        $pdo->exec("DELETE FROM \"{$safeTable}\"");
    }

    $pdo->exec("COMMIT");

    // 3) Сброс AUTOINCREMENT (если таблица есть)
    try {
        $pdo->exec("DELETE FROM sqlite_sequence");
    } catch (Throwable $e) {
        // ignore
    }

    // 4) Сжатие файла БД
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA wal_checkpoint(FULL)");
    $pdo->exec("VACUUM");
    $pdo->exec("ANALYZE");

    echo "✅ Done\n";
} catch (Throwable $e) {
    fwrite(STDERR, "❌ Error: " . $e->getMessage() . "\n");
    exit(1);
}