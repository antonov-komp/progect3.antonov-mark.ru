<?php
/**
 * Проверка: какой процессор будет использован
 * Запускать через веб-сервер для проверки реальной конфигурации
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Проверка процессора событий (через веб-сервер)\n";
echo "==============================================\n\n";

try {
    $container = new ServiceContainer();
    
    // Проверка конфигурации
    $config = $container->get('config');
    $dbType = $config->get('DATABASE_TYPE', 'not set');
    echo "1. DATABASE_TYPE: {$dbType}\n";
    
    // Проверка расширения
    echo "2. Расширение pdo_sqlite: " . (extension_loaded('pdo_sqlite') ? 'загружено ✓' : 'НЕ загружено ✗') . "\n";
    
    // Проверка доступных драйверов
    $drivers = PDO::getAvailableDrivers();
    echo "3. Доступные PDO драйверы: " . implode(', ', $drivers) . "\n";
    echo "   SQLite доступен: " . (in_array('sqlite', $drivers) ? 'да ✓' : 'нет ✗') . "\n\n";
    
    // Проверка репозиториев
    $hasEventRepo = $container->has('eventRepository');
    $hasQueueRepo = $container->has('queueRepository');
    echo "4. eventRepository доступен: " . ($hasEventRepo ? 'да ✓' : 'нет ✗') . "\n";
    echo "5. queueRepository доступен: " . ($hasQueueRepo ? 'да ✓' : 'нет ✗') . "\n\n";
    
    // Симуляция логики из index.php
    $useDatabase = $dbType === 'sqlite';
    echo "6. useDatabase (из конфига): " . ($useDatabase ? 'да' : 'нет') . "\n";
    
    $processor = null;
    $processorType = 'не определен';
    $error = null;
    
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
                $processorType = 'DatabaseEventProcessor ✓';
            } else {
                $processorType = 'Репозитории недоступны ✗';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $processorType = 'Ошибка при создании: ' . $e->getMessage() . ' ✗';
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
        $processorType = 'EventProcessor (файлы) ⚠';
    }
    
    echo "\n7. Используемый процессор: {$processorType}\n";
    echo "   Класс: " . get_class($processor) . "\n";
    
    if ($error) {
        echo "\nДетали ошибки:\n";
        echo $error . "\n";
    }
    
    if ($processorType === 'EventProcessor (файлы) ⚠') {
        echo "\n⚠ ВНИМАНИЕ: Используется файловая система вместо БД!\n";
        echo "\nВозможные причины:\n";
        if (!extension_loaded('pdo_sqlite')) {
            echo "1. ✗ Расширение pdo_sqlite не загружено для веб-сервера\n";
            echo "   Решение: Перезапустите PHP-FPM после установки расширения\n";
        }
        if (!$hasEventRepo || !$hasQueueRepo) {
            echo "2. ✗ Репозитории недоступны\n";
        }
        if ($error) {
            echo "3. ✗ Ошибка при создании DatabaseEventProcessor\n";
        }
    } else {
        echo "\n✓ Используется БД для хранения событий\n";
    }
    
} catch (Throwable $e) {
    echo "\n✗ Критическая ошибка: {$e->getMessage()}\n";
    echo "Трассировка:\n{$e->getTraceAsString()}\n";
}
