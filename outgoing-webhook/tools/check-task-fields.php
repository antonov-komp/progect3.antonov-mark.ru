<?php
declare(strict_types=1);

/**
 * Проверка полей задачи и поиск информации о трудозатратах
 */

require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

$taskId = isset($argv[1]) ? (int)$argv[1] : 1481;
$client = new Bitrix24Client();

echo "Проверка полей задачи #$taskId и поиск информации о трудозатратах...\n\n";

// Получаем задачу со всеми полями
$result = $client->call('tasks.task.get', [
    'id' => $taskId,
    'select' => ['*']  // Все поля
]);

if (!empty($result['error'])) {
    echo "Ошибка получения задачи: {$result['error']}\n";
    echo "Информация: {$result['error_information']}\n";
    exit(1);
}

$task = $result['result']['task'] ?? $result['result'] ?? null;

if (!$task) {
    echo "Задача не найдена\n";
    exit(1);
}

echo "=== Основная информация о задаче ===\n";
echo "ID: " . ($task['id'] ?? 'N/A') . "\n";
echo "Название: " . ($task['title'] ?? 'N/A') . "\n";
echo "Статус: " . ($task['status'] ?? 'N/A') . "\n\n";

// Ищем поля, связанные с временем и трудозатратами
echo "=== Поля, связанные с временем и трудозатратами ===\n";
$timeFields = [
    'timeSpent',
    'timeSpentInLogs',
    'timeEstimate',
    'elapsedTime',
    'elapsedItem',
    'elapsedItems',
    'TIME_SPENT',
    'TIME_SPENT_IN_LOGS',
    'TIME_ESTIMATE',
    'ELAPSED_TIME',
    'ELAPSED_ITEM',
    'ELAPSED_ITEMS',
];

foreach ($timeFields as $field) {
    if (isset($task[$field])) {
        echo "$field: " . json_encode($task[$field], JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// Проверяем все ключи, содержащие "time", "elapsed", "spent"
echo "\n=== Все ключи, содержащие 'time', 'elapsed', 'spent' ===\n";
foreach ($task as $key => $value) {
    $keyLower = strtolower($key);
    if (strpos($keyLower, 'time') !== false || 
        strpos($keyLower, 'elapsed') !== false || 
        strpos($keyLower, 'spent') !== false) {
        echo "$key: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// Пробуем получить через tasks.task.list с фильтром
echo "\n=== Попытка получить через tasks.task.list ===\n";
$listResult = $client->call('tasks.task.list', [
    'filter' => ['ID' => $taskId],
    'select' => ['*']
]);

if (empty($listResult['error'])) {
    echo "tasks.task.list работает\n";
    if (!empty($listResult['result']['tasks'])) {
        $taskFromList = $listResult['result']['tasks'][0];
        echo "Найдено полей в tasks.task.list: " . count($taskFromList) . "\n";
        
        // Ищем поля времени
        foreach ($taskFromList as $key => $value) {
            $keyLower = strtolower($key);
            if (strpos($keyLower, 'time') !== false || 
                strpos($keyLower, 'elapsed') !== false || 
                strpos($keyLower, 'spent') !== false) {
                echo "$key: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }
} else {
    echo "tasks.task.list недоступен: {$listResult['error']}\n";
}

// Пробуем другие варианты методов для трудозатрат
echo "\n=== Попытка альтернативных методов для трудозатрат ===\n";
$alternativeMethods = [
    'tasks.elapseditem.get',
    'tasks.elapseditem.list',
    'tasks.task.elapseditem',
    'tasks.elapseditem',
    'tasks.task.getElapsedItems',
    'tasks.getElapsedItems',
];

foreach ($alternativeMethods as $method) {
    $testResult = $client->call($method, [
        'TASKID' => $taskId,
        'id' => $taskId,
    ]);
    
    if (empty($testResult['error'])) {
        echo "✅ $method работает!\n";
        echo json_encode($testResult['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// Выводим полную структуру задачи для анализа
echo "\n=== Полная структура задачи (первые 1000 символов) ===\n";
$fullJson = json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo substr($fullJson, 0, 1000) . "...\n";

echo "\n=== Все ключи задачи ===\n";
echo implode(', ', array_keys($task)) . "\n";
