<?php
declare(strict_types=1);

/**
 * Попытка принудительно запустить таймер задачи через доступные методы REST API
 * 
 * Использование:
 *   php force-start-timer.php 1481
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

$taskId = isset($argv[1]) ? (int)$argv[1] : 1481;
$client = new Bitrix24Client();

echo "Попытка принудительно запустить таймер для задачи #$taskId\n\n";

// 1. Получаем текущее состояние задачи
echo "1. Получаем текущее состояние задачи...\n";
$taskResult = $client->call('tasks.task.get', [
    'id' => $taskId,
    'select' => ['*']
]);

if (!empty($taskResult['error'])) {
    echo "Ошибка получения задачи: {$taskResult['error']}\n";
    exit(1);
}

$task = $taskResult['result']['task'] ?? $taskResult['result'];
$currentStatus = (int)($task['status'] ?? 0);
$allowTimeTracking = ($task['allowTimeTracking'] ?? 'N') === 'Y';
$timeSpentInLogs = $task['timeSpentInLogs'] ?? null;

echo "   Статус задачи: $currentStatus\n";
echo "   Учет времени разрешен: " . ($allowTimeTracking ? 'Да' : 'Нет') . "\n";
echo "   Затраченное время: " . ($timeSpentInLogs ?? 'null') . "\n\n";

if (!$allowTimeTracking) {
    echo "❌ Учет времени отключен для этой задачи. Включите его в настройках задачи.\n";
    exit(1);
}

// 2. Пробуем изменить статус на "Выполняется" (может запустить таймер косвенно)
echo "2. Пробуем изменить статус на 'Выполняется' (3)...\n";

if ($currentStatus !== 3) {
    $updateResult = $client->call('tasks.task.update', [
        'id' => $taskId,
        'fields' => [
            'status' => 3  // Статус "Выполняется"
        ]
    ]);
    
    if (!empty($updateResult['error'])) {
        echo "   ❌ Ошибка изменения статуса: {$updateResult['error']}\n";
        echo "   Информация: {$updateResult['error_information']}\n\n";
    } else {
        echo "   ✅ Статус изменен на 'Выполняется'\n";
        echo "   ⚠️  ВНИМАНИЕ: Это может не запустить таймер автоматически!\n";
        echo "   Таймер нужно запустить вручную через UI Bitrix24.\n\n";
    }
} else {
    echo "   Статус уже 'Выполняется'\n\n";
}

// 3. Пробуем методы для добавления трудозатрат (скорее всего не сработают)
echo "3. Пробуем методы для добавления трудозатрат...\n";

$methods = [
    'tasks.task.elapseditem.add',
    'tasks.elapseditem.add',
    'tasks.task.elapseditem',
    'tasks.elapseditem',
];

$success = false;
foreach ($methods as $method) {
    echo "   Пробуем: $method\n";
    
    $result = $client->call($method, [
        'TASKID' => $taskId,
        'id' => $taskId,
        'FIELDS' => [
            'COMMENT_TEXT' => 'Принудительный запуск таймера через API',
            'DATE_START' => date('Y-m-d H:i:s'),
        ]
    ]);
    
    if (empty($result['error'])) {
        echo "   ✅ Успех! Метод $method сработал!\n";
        echo "   Результат: " . json_encode($result['result'], JSON_UNESCAPED_UNICODE) . "\n";
        $success = true;
        break;
    } else {
        echo "   ❌ Ошибка: {$result['error']}\n";
    }
}

if (!$success) {
    echo "\n   ❌ Все методы недоступны\n\n";
}

// 4. Проверяем результат через несколько секунд
echo "4. Проверяем результат через 3 секунды...\n";
sleep(3);

$checkResult = $client->call('tasks.task.get', [
    'id' => $taskId,
    'select' => ['timeSpentInLogs', 'status', 'allowTimeTracking']
]);

if (empty($checkResult['error'])) {
    $updatedTask = $checkResult['result']['task'] ?? $checkResult['result'];
    $newTimeSpent = $updatedTask['timeSpentInLogs'] ?? null;
    $newStatus = $updatedTask['status'] ?? null;
    
    echo "   Новый статус: $newStatus\n";
    echo "   Новое затраченное время: " . ($newTimeSpent ?? 'null') . "\n";
    
    if ($newTimeSpent !== null && $newTimeSpent !== $timeSpentInLogs) {
        echo "   ✅ Время изменилось! Таймер может быть запущен.\n";
    } else {
        echo "   ⚠️  Время не изменилось. Таймер нужно запустить вручную через UI Bitrix24.\n";
    }
}

// 5. Итоговые рекомендации
echo "\n=== ИТОГОВЫЕ РЕКОМЕНДАЦИИ ===\n";
echo "Методы tasks.task.elapseditem.* недоступны в REST API Bitrix24.\n";
echo "\nДля принудительного запуска таймера:\n";
echo "1. Откройте задачу в Bitrix24\n";
echo "2. Нажмите кнопку 'Начать' в интерфейсе задачи\n";
echo "3. Или отслеживайте изменения через вебхуки ONTASKUPDATE\n";
echo "\nАльтернатива:\n";
echo "- Используйте изменение статуса задачи на 'Выполняется' (уже сделано)\n";
echo "- Отслеживайте изменения timeSpentInLogs через вебхуки\n";
echo "- Реагируйте на изменения в реальном времени\n";
