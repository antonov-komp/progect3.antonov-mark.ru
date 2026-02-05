<?php
declare(strict_types=1);

/**
 * Проверка состояния таймера после изменения статуса задачи
 * 
 * Использование:
 *   php check-timer-after-status-change.php 1481
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

$taskId = isset($argv[1]) ? (int)$argv[1] : 1481;
$client = new Bitrix24Client();

echo "Проверка состояния таймера для задачи #$taskId после изменения статуса\n\n";

// Получаем текущее состояние задачи
$taskResult = $client->call('tasks.task.get', [
    'id' => $taskId,
    'select' => ['*']
]);

if (!empty($taskResult['error'])) {
    echo "Ошибка получения задачи: {$taskResult['error']}\n";
    exit(1);
}

$task = $taskResult['result']['task'] ?? $taskResult['result'];

echo "=== Текущее состояние задачи ===\n";
echo "ID: {$task['id']}\n";
echo "Название: {$task['title']}\n";
echo "Статус: {$task['status']} ";

// Расшифровка статусов
$statusNames = [
    '1' => 'Новая',
    '2' => 'Ждет выполнения',
    '3' => 'Выполняется',
    '4' => 'Ждет контроля',
    '5' => 'Завершена',
    '6' => 'Отложена',
    '7' => 'Отклонена',
];

$statusName = isset($statusNames[$task['status']]) ? $statusNames[$task['status']] : 'Неизвестный';
echo "($statusName)\n";
$allowTimeTracking = isset($task['allowTimeTracking']) ? $task['allowTimeTracking'] : 'N';
$timeSpentInLogs = isset($task['timeSpentInLogs']) ? $task['timeSpentInLogs'] : null;
$timeEstimate = isset($task['timeEstimate']) ? $task['timeEstimate'] : '0';

echo "Учет времени разрешен: " . ($allowTimeTracking === 'Y' ? 'Да' : 'Нет') . "\n";
echo "Затраченное время (timeSpentInLogs): " . ($timeSpentInLogs !== null ? $timeSpentInLogs : 'null') . "\n";
echo "Плановое время (timeEstimate): " . $timeEstimate . "\n\n";

// Проверяем, есть ли активный таймер
if ($task['timeSpentInLogs'] === null || $task['timeSpentInLogs'] === '') {
    echo "❌ Таймер НЕ запущен\n";
    echo "   timeSpentInLogs = null означает, что таймер не активен\n\n";
} else {
    echo "✅ Есть данные о затраченном времени: {$task['timeSpentInLogs']}\n";
    echo "   Но это не означает, что таймер активен сейчас\n";
    echo "   (это может быть накопленное время из предыдущих сессий)\n\n";
}

// Проверяем статус
if ((int)$task['status'] === 3) {
    echo "✅ Статус задачи: 'Выполняется' (3)\n";
    echo "   Но таймер не запустился автоматически\n\n";
    
    echo "=== ВЫВОД ===\n";
    echo "Изменение статуса на 'Выполняется' НЕ запускает таймер автоматически.\n";
    echo "Таймер и статус задачи - это независимые сущности в Bitrix24.\n\n";
    
    echo "=== ЧТО ДЕЛАТЬ ===\n";
    echo "1. Откройте задачу #$taskId в Bitrix24\n";
    echo "2. Найдите кнопку 'Начать' или 'Запустить таймер' в интерфейсе задачи\n";
    echo "3. Нажмите на неё - таймер запустится\n";
    echo "4. После запуска timeSpentInLogs начнет увеличиваться\n\n";
    
    echo "=== АЛЬТЕРНАТИВА (для автоматизации) ===\n";
    echo "Настройте вебхук ONTASKUPDATE для отслеживания изменений:\n";
    echo "- Отслеживайте изменения поля TIME_SPENT_IN_LOGS\n";
    echo "- Когда оно начинает увеличиваться - таймер запущен\n";
    echo "- Реагируйте на эти изменения в вашем коде\n";
} else {
    $statusName = isset($statusNames[$task['status']]) ? $statusNames[$task['status']] : 'Неизвестный';
    echo "⚠️  Статус задачи: $statusName ({$task['status']})\n";
    echo "   Для запуска таймера обычно нужен статус 'Выполняется' (3)\n";
    echo "   Но даже при статусе 'Выполняется' таймер нужно запускать вручную\n";
}

// Дополнительная информация
echo "\n=== ДОПОЛНИТЕЛЬНАЯ ИНФОРМАЦИЯ ===\n";
echo "Поля задачи, связанные с временем:\n";
echo "- allowTimeTracking: " . (isset($task['allowTimeTracking']) ? $task['allowTimeTracking'] : 'N') . "\n";
echo "- timeEstimate: " . (isset($task['timeEstimate']) ? $task['timeEstimate'] : '0') . " (плановое время)\n";
$timeSpent = isset($task['timeSpentInLogs']) ? $task['timeSpentInLogs'] : 'null';
echo "- timeSpentInLogs: " . ($timeSpent !== null ? $timeSpent : 'null') . " (затраченное время)\n";
echo "- matchWorkTime: " . (isset($task['matchWorkTime']) ? $task['matchWorkTime'] : 'N') . "\n";

echo "\n=== ПРОВЕРКА ЧЕРЕЗ НЕСКОЛЬКО СЕКУНД ===\n";
echo "Подождите 5 секунд и проверьте снова...\n";
sleep(5);

$checkResult = $client->call('tasks.task.get', [
    'id' => $taskId,
    'select' => ['timeSpentInLogs', 'status']
]);

if (empty($checkResult['error'])) {
    $updatedTask = isset($checkResult['result']['task']) ? $checkResult['result']['task'] : $checkResult['result'];
    $newTimeSpent = isset($updatedTask['timeSpentInLogs']) ? $updatedTask['timeSpentInLogs'] : null;
    $oldTimeSpent = isset($task['timeSpentInLogs']) ? $task['timeSpentInLogs'] : null;
    
    if ($newTimeSpent !== null && $newTimeSpent !== $oldTimeSpent) {
        echo "✅ Время изменилось! Таймер может быть запущен.\n";
        echo "   Было: " . ($oldTimeSpent !== null ? $oldTimeSpent : 'null') . "\n";
        echo "   Стало: $newTimeSpent\n";
    } else {
        echo "❌ Время не изменилось. Таймер не запущен.\n";
        echo "   timeSpentInLogs: " . ($newTimeSpent !== null ? $newTimeSpent : 'null') . "\n";
    }
}
