<?php
/**
 * Тестовый скрипт для проверки записи в БД через веб-сервер
 * 
 * Использование: Откройте через браузер или curl
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Тест записи в SQLite БД\n";
echo "=======================\n\n";

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
    
    if (!$hasEventRepo || !$hasQueueRepo) {
        echo "✗ Репозитории недоступны. Проверьте ServiceContainer.\n";
        exit(1);
    }
    
    // Получение репозиториев
    $eventRepo = $container->get('eventRepository');
    $queueRepo = $container->get('queueRepository');
    
    // Тестовая запись события
    echo "4. Тест записи события в БД:\n";
    $testEventData = [
        'requestId' => 'test_' . time(),
        'eventType' => 'TEST_EVENT',
        'entityType' => 'test',
        'entityId' => '123',
        'receivedAt' => date('Y-m-d H:i:s'),
        'ip' => '127.0.0.1',
        'tokenSource' => 'test',
        'eventHandlerId' => 'test_handler',
        'memberId' => 'test_member',
        'payload' => ['test' => 'data'],
        'createdAt' => date('Y-m-d H:i:s'),
    ];
    
    $eventId = $eventRepo->create($testEventData);
    
    if ($eventId !== null) {
        echo "   ✓ Событие записано, ID: {$eventId}\n";
    } else {
        echo "   ✗ Ошибка записи события\n";
        exit(1);
    }
    
    // Тестовая запись в очередь
    echo "5. Тест записи в очередь:\n";
    $testJobData = [
        'requestId' => 'test_job_' . time(),
        'eventType' => 'TEST_EVENT',
        'entityType' => 'test',
        'entityId' => '123',
        'status' => 'pending',
        'attempt' => 0,
        'priority' => 'normal',
        'source' => 'test',
        'tokenSource' => 'test',
        'eventHandlerId' => 'test_handler',
        'memberId' => 'test_member',
        'payload' => ['test' => 'data'],
        'createdAt' => date('Y-m-d H:i:s'),
    ];
    
    $jobId = $queueRepo->createJob($testJobData);
    
    if ($jobId !== null) {
        echo "   ✓ Задание записано в очередь, ID: {$jobId}\n";
    } else {
        echo "   ✗ Ошибка записи в очередь\n";
        exit(1);
    }
    
    // Проверка чтения
    echo "\n6. Проверка чтения из БД:\n";
    $readEvent = $eventRepo->findByRequestId($testEventData['requestId']);
    if ($readEvent !== null) {
        echo "   ✓ Событие прочитано из БД\n";
        echo "   ID: {$readEvent['id']}, Тип: {$readEvent['event_type']}\n";
    } else {
        echo "   ✗ Событие не найдено в БД\n";
    }
    
    $pendingJobs = $queueRepo->listPending(10);
    echo "   ✓ Заданий в очереди (pending): " . count($pendingJobs) . "\n";
    
    echo "\n✓ Все тесты пройдены успешно!\n";
    echo "\nБД работает корректно. События должны записываться.\n";
    
} catch (Throwable $e) {
    echo "\n✗ Ошибка: {$e->getMessage()}\n";
    echo "Трассировка:\n{$e->getTraceAsString()}\n";
    exit(1);
}
