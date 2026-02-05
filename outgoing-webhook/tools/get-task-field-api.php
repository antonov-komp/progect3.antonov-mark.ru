<?php
declare(strict_types=1);

/**
 * Получить значение поля задачи через прямой запрос к API
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';
require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

$taskId = $argv[1] ?? '1481';
$fieldName = $argv[2] ?? 'autocompleteSubTasks';

$client = new Bitrix24Client();

echo "🔍 Запрос поля '{$fieldName}' для задачи {$taskId} через API\n";
echo str_repeat("=", 80) . "\n";

// Запрос с указанием конкретного поля
$result = $client->call('tasks.task.get', [
    'id' => $taskId,
    'select' => [$fieldName],
]);

if (!empty($result['error'])) {
    echo "❌ Ошибка API: " . json_encode($result['error']) . "\n";
    exit(1);
}

$task = $result['result']['task'] ?? null;

if ($task === null) {
    echo "❌ Задача не найдена\n";
    exit(1);
}

if (isset($task[$fieldName])) {
    $value = $task[$fieldName];
    echo "✅ Поле найдено!\n";
    echo "Значение: " . json_encode($value) . "\n";
    echo "Тип: " . gettype($value) . "\n";
    
    // Для boolean полей показываем интерпретацию
    if ($fieldName === 'autocompleteSubTasks') {
        if ($value === 'Y' || $value === true || $value === '1') {
            echo "Интерпретация: Автозавершение подзадач ВКЛЮЧЕНО\n";
        } elseif ($value === 'N' || $value === false || $value === '0') {
            echo "Интерпретация: Автозавершение подзадач ВЫКЛЮЧЕНО\n";
        }
    }
} else {
    echo "❌ Поле '{$fieldName}' не найдено в ответе API\n";
    echo "\nДоступные поля в ответе:\n";
    foreach (array_keys($task) as $key) {
        echo "  - {$key}\n";
    }
}

echo "\n";
