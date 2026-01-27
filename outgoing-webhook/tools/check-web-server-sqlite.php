<?php
/**
 * Проверка SQLite через веб-сервер
 * Откройте через браузер: http://ваш-домен/outgoing-webhook/tools/check-web-server-sqlite.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "Проверка SQLite для веб-сервера\n";
echo "================================\n\n";

// 1. Проверка расширения
echo "1. Расширение pdo_sqlite:\n";
if (extension_loaded('pdo_sqlite')) {
    echo "   ✓ Загружено\n";
} else {
    echo "   ✗ НЕ загружено\n";
    echo "   Решение: sudo apt-get install php8.3-sqlite3 && sudo systemctl restart php8.3-fpm\n";
    exit(1);
}

// 2. Проверка драйверов PDO
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

// 3. Проверка подключения к БД
echo "\n3. Проверка подключения к БД:\n";
$dbPath = __DIR__ . '/../database/events.db';
echo "   Путь: {$dbPath}\n";

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "   ✓ Подключение успешно\n";
    
    // Проверка таблиц
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    echo "   ✓ Таблиц в БД: " . count($tables) . "\n";
    
    // Количество событий
    if (in_array('events', $tables)) {
        $count = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
        echo "   ✓ Событий в таблице events: {$count}\n";
    }
    
    // Тест записи
    echo "\n4. Тест записи в БД:\n";
    $testRequestId = 'web_test_' . time();
    $stmt = $pdo->prepare("INSERT INTO events (request_id, event_type, entity_type, entity_id, received_at, ip, payload, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $result = $stmt->execute([
        $testRequestId,
        'WEB_TEST',
        'test',
        '123',
        date('Y-m-d H:i:s'),
        '127.0.0.1',
        json_encode(['test' => 'data']),
        date('Y-m-d H:i:s'),
    ]);
    
    if ($result) {
        $eventId = $pdo->lastInsertId();
        echo "   ✓ Тестовое событие записано, ID: {$eventId}\n";
        
        // Удаляем тестовое событие
        $pdo->exec("DELETE FROM events WHERE id = {$eventId}");
        echo "   ✓ Тестовое событие удалено\n";
    } else {
        echo "   ✗ Ошибка записи\n";
    }
    
    echo "\n✓ Все проверки пройдены успешно!\n";
    echo "\nВеб-сервер может работать с SQLite.\n";
    echo "Если события не записываются, проверьте логи после создания события.\n";
    
} catch (PDOException $e) {
    echo "   ✗ Ошибка подключения: {$e->getMessage()}\n";
    exit(1);
} catch (Throwable $e) {
    echo "   ✗ Ошибка: {$e->getMessage()}\n";
    exit(1);
}
