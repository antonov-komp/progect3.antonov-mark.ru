<?php
declare(strict_types=1);

/**
 * Получить значение autocompleteSubTasks для задачи через прямой запрос к API
 * 
 * Использование: php get-autocomplete-subtasks.php [task_id]
 */

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/crest.php';
require_once dirname(__DIR__, 2) . '/app/Services/Bitrix24Client.php';

$taskId = $argv[1] ?? '1481';

$client = new Bitrix24Client();

echo "🔍 Запрос поля 'autocompleteSubTasks' для задачи {$taskId}\n";
echo str_repeat("=", 80) . "\n";

// Сначала проверим, что возвращается по умолчанию
echo "\n1️⃣ Запрос БЕЗ select (все поля по умолчанию):\n";
$resultDefault = $client->call('tasks.task.get', ['id' => $taskId]);
$taskDefault = $resultDefault['result']['task'] ?? [];
echo "   Всего полей: " . count($taskDefault) . "\n";
if (isset($taskDefault['autocompleteSubTasks'])) {
    echo "   ✅ autocompleteSubTasks найден: " . json_encode($taskDefault['autocompleteSubTasks']) . "\n";
} else {
    echo "   ❌ autocompleteSubTasks НЕ найден в полях по умолчанию\n";
}

// Запрос с указанием конкретного поля
echo "\n2️⃣ Запрос с select = ['autocompleteSubTasks']:\n";
$result = $client->call('tasks.task.get', [
    'id' => $taskId,
    'select' => ['autocompleteSubTasks'],
]);

if (!empty($result['error'])) {
    echo "❌ Ошибка API:\n";
    echo json_encode($result['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$task = $result['result']['task'] ?? null;

if ($task === null) {
    echo "❌ Задача не найдена в ответе API\n";
    echo "\nПолный ответ:\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

// Запрос с select = ['*', 'autocompleteSubTasks']
echo "\n3️⃣ Запрос с select = ['*', 'autocompleteSubTasks']:\n";
$resultAll = $client->call('tasks.task.get', [
    'id' => $taskId,
    'select' => ['*', 'autocompleteSubTasks'],
]);
$taskAll = $resultAll['result']['task'] ?? [];
if (isset($taskAll['autocompleteSubTasks'])) {
    echo "   ✅ autocompleteSubTasks найден: " . json_encode($taskAll['autocompleteSubTasks']) . "\n";
} else {
    echo "   ❌ autocompleteSubTasks НЕ найден\n";
}

echo "\n📋 Ответ API (вариант 2 с select):\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo "\n";

// Проверка всех полей с похожими названиями
echo "🔍 Поиск похожих полей (содержащих 'auto', 'complete', 'sub'):\n";
$allFields = array_merge($taskDefault, $taskAll, $task);
foreach ($allFields as $key => $val) {
    if (stripos($key, 'complete') !== false || stripos($key, 'sub') !== false || stripos($key, 'auto') !== false) {
        echo "   - {$key}: " . json_encode($val) . "\n";
    }
}
echo "\n";

if (isset($task['autocompleteSubTasks'])) {
    $value = $task['autocompleteSubTasks'];
    echo "✅ Поле найдено!\n";
    echo str_repeat("-", 80) . "\n";
    echo "Значение: " . json_encode($value) . "\n";
    echo "Тип: " . gettype($value) . "\n";
    
    // Интерпретация для boolean полей
    if ($value === 'Y' || $value === true || $value === '1' || $value === 1) {
        echo "Интерпретация: ✅ Автозавершение подзадач ВКЛЮЧЕНО (Y)\n";
    } elseif ($value === 'N' || $value === false || $value === '0' || $value === 0 || $value === null) {
        echo "Интерпретация: ❌ Автозавершение подзадач ВЫКЛЮЧЕНО (N)\n";
    } else {
        echo "Интерпретация: ⚠️  Неизвестное значение\n";
    }
} else {
    echo "❌ Поле 'autocompleteSubTasks' не найдено в ответе API\n";
    echo "\nДоступные поля в ответе:\n";
    foreach (array_keys($task) as $key) {
        $val = $task[$key];
        $display = is_array($val) ? 'array(' . count($val) . ')' : json_encode($val);
        echo "  - {$key}: {$display}\n";
    }
}

echo "\n";
echo str_repeat("=", 80) . "\n";
