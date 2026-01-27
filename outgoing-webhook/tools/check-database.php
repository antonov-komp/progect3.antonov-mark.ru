<?php
/**
 * Скрипт проверки доступности SQLite для веб-сервера
 */

echo "Проверка SQLite для веб-сервера\n";
echo "================================\n\n";

// Проверка расширения
echo "1. Проверка расширения PDO_SQLITE:\n";
if (extension_loaded('pdo_sqlite')) {
    echo "   ✓ Расширение загружено\n";
} else {
    echo "   ✗ Расширение НЕ загружено\n";
    echo "   Решение: sudo apt-get install php8.3-sqlite3 && sudo systemctl restart php8.3-fpm\n";
    exit(1);
}

// Проверка доступных драйверов PDO
echo "\n2. Доступные PDO драйверы:\n";
$drivers = PDO::getAvailableDrivers();
foreach ($drivers as $driver) {
    echo "   - {$driver}\n";
}

if (!in_array('sqlite', $drivers)) {
    echo "   ✗ SQLite драйвер недоступен\n";
    exit(1);
} else {
    echo "   ✓ SQLite драйвер доступен\n";
}

// Проверка подключения к БД
echo "\n3. Проверка подключения к БД:\n";
$dbPath = __DIR__ . '/../database/events.db';
echo "   Путь: {$dbPath}\n";

if (!file_exists($dbPath)) {
    echo "   ⚠ Файл БД не существует (это нормально при первом запуске)\n";
} else {
    echo "   ✓ Файл БД существует\n";
    echo "   Размер: " . filesize($dbPath) . " байт\n";
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "   ✓ Подключение успешно\n";
    
    // Проверка таблиц
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    echo "   ✓ Таблиц в БД: " . count($tables) . "\n";
    
    if (in_array('events', $tables)) {
        $count = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
        echo "   ✓ Событий в таблице events: {$count}\n";
    }
} catch (PDOException $e) {
    echo "   ✗ Ошибка подключения: {$e->getMessage()}\n";
    exit(1);
}

echo "\n✓ Все проверки пройдены успешно!\n";
