<?php
/**
 * Проверка событий в БД
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $container = new ServiceContainer();
    $db = $container->get('database');
    $conn = $db->getConnection();
    
    echo "Проверка событий в БД\n";
    echo "====================\n\n";
    
    // Количество событий
    $eventsCount = $conn->query('SELECT COUNT(*) FROM events')->fetchColumn();
    echo "Всего событий: {$eventsCount}\n";
    
    // Количество заданий в очереди
    $queueCount = $conn->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn();
    echo "Заданий в очереди: {$queueCount}\n\n";
    
    // Последние 5 событий
    if ($eventsCount > 0) {
        echo "Последние 5 событий:\n";
        $events = $conn->query("SELECT id, request_id, event_type, entity_type, entity_id, received_at FROM events ORDER BY received_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as $event) {
            echo sprintf(
                "  ID: %d | %s | %s | %s | %s | %s\n",
                $event['id'],
                $event['request_id'],
                $event['event_type'],
                $event['entity_type'] ?? 'null',
                $event['entity_id'] ?? 'null',
                $event['received_at']
            );
        }
    } else {
        echo "⚠ Событий в БД нет!\n";
        echo "\nВозможные причины:\n";
        echo "1. События записываются в файлы вместо БД\n";
        echo "2. DatabaseEventProcessor не используется\n";
        echo "3. Ошибки при записи в БД (проверьте логи)\n";
    }
    
} catch (Throwable $e) {
    echo "Ошибка: {$e->getMessage()}\n";
}
