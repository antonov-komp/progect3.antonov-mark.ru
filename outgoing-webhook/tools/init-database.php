<?php
declare(strict_types=1);

/**
 * Скрипт инициализации SQLite базы данных
 * 
 * Использование:
 * php tools/init-database.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

// Проверка наличия расширения PDO_SQLITE
if (!extension_loaded('pdo_sqlite')) {
    echo "✗ Ошибка: Расширение PDO_SQLITE не установлено\n\n";
    echo "Решение:\n";
    echo "  sudo apt-get install php8.3-sqlite3\n";
    echo "  sudo systemctl restart php8.3-fpm\n\n";
    echo "Подробнее: outgoing-webhook/database/QUICK_FIX.md\n";
    exit(1);
}

try {
    $container = new ServiceContainer();
    $config = $container->get('config');
    $errors = $container->get('errors');
    
    $dbPath = $config->get('DATABASE_PATH', __DIR__ . '/../database/events.db');
    $walEnabled = $config->get('DATABASE_WAL_ENABLED', 'true') === 'true';
    
    echo "Инициализация SQLite базы данных...\n";
    echo "Путь к БД: {$dbPath}\n";
    echo "WAL режим: " . ($walEnabled ? 'включен' : 'выключен') . "\n\n";
    
    // Создание DatabaseService
    $database = new DatabaseService($dbPath, $errors, $walEnabled);
    
    // Инициализация схемы
    $schemaPath = dirname(__DIR__) . '/database/schema.sqlite.sql';
    
    if (!file_exists($schemaPath)) {
        throw new RuntimeException("Файл схемы не найден: {$schemaPath}");
    }
    
    echo "Загрузка схемы из: {$schemaPath}\n";
    
    if ($database->initializeSchema($schemaPath)) {
        echo "✓ Схема БД успешно инициализирована\n";
        
        // Проверка подключения
        $connection = $database->getConnection();
        $version = $connection->query('SELECT sqlite_version()')->fetchColumn();
        echo "✓ Подключение к SQLite успешно (версия: {$version})\n";
        
        // Проверка таблиц
        $tables = $connection->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        echo "✓ Создано таблиц: " . count($tables) . "\n";
        echo "  Таблицы: " . implode(', ', $tables) . "\n";
        
        // Проверка WAL режима
        if ($walEnabled) {
            $walMode = $connection->query("PRAGMA journal_mode")->fetchColumn();
            echo "✓ Режим журнала: {$walMode}\n";
        }
        
        echo "\n✓ База данных готова к использованию!\n";
    } else {
        throw new RuntimeException("Не удалось инициализировать схему БД");
    }
} catch (Throwable $e) {
    echo "✗ Ошибка: {$e->getMessage()}\n";
    echo "Трассировка:\n{$e->getTraceAsString()}\n";
    exit(1);
}
