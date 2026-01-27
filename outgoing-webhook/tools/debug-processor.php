<?php
/**
 * Скрипт для отладки: какой процессор используется
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Отладка процессора событий\n";
echo "==========================\n\n";

try {
    $container = new ServiceContainer();
    
    // Проверка конфигурации
    $config = $container->get('config');
    $dbType = $config->get('DATABASE_TYPE', 'not set');
    echo "1. DATABASE_TYPE: {$dbType}\n";
    
    // Проверка доступности репозиториев
    $hasEventRepo = $container->has('eventRepository');
    $hasQueueRepo = $container->has('queueRepository');
    echo "2. eventRepository доступен: " . ($hasEventRepo ? 'да' : 'нет') . "\n";
    echo "3. queueRepository доступен: " . ($hasQueueRepo ? 'да' : 'нет') . "\n\n";
    
    // Симуляция логики из index.php
    $useDatabase = $config->get('DATABASE_TYPE', 'sqlite') === 'sqlite';
    echo "4. useDatabase (из конфига): " . ($useDatabase ? 'да' : 'нет') . "\n";
    
    $processor = null;
    $processorType = 'не определен';
    
    if ($useDatabase) {
        try {
            if ($hasEventRepo && $hasQueueRepo) {
                $eventRepo = $container->get('eventRepository');
                $queueRepo = $container->get('queueRepository');
                
                $processor = new DatabaseEventProcessor(
                    $container->get('request'),
                    $container->get('identity'),
                    $container->get('filesystem'),
                    $container->get('errors'),
                    $container->get('formatter'),
                    $eventRepo,
                    $queueRepo
                );
                $processorType = 'DatabaseEventProcessor';
            }
        } catch (Throwable $e) {
            echo "5. Ошибка при создании DatabaseEventProcessor:\n";
            echo "   " . $e->getMessage() . "\n";
            $processorType = 'ошибка: ' . $e->getMessage();
        }
    }
    
    if ($processor === null) {
        $processor = new EventProcessor(
            $container->get('request'),
            $container->get('identity'),
            $container->get('filesystem'),
            $container->get('errors'),
            $container->get('formatter')
        );
        $processorType = 'EventProcessor (файлы)';
    }
    
    echo "\n5. Используемый процессор: {$processorType}\n";
    echo "   Класс: " . get_class($processor) . "\n";
    
    if ($processorType === 'EventProcessor (файлы)') {
        echo "\n⚠ ВНИМАНИЕ: Используется файловая система вместо БД!\n";
        echo "   Проверьте:\n";
        echo "   1. Перезапущен ли PHP-FPM после установки sqlite3?\n";
        echo "   2. Доступно ли расширение pdo_sqlite для веб-сервера?\n";
        echo "   3. Есть ли ошибки в логах?\n";
    } else {
        echo "\n✓ Используется БД для хранения событий\n";
    }
    
} catch (Throwable $e) {
    echo "\n✗ Ошибка: {$e->getMessage()}\n";
    echo "Трассировка:\n{$e->getTraceAsString()}\n";
}
