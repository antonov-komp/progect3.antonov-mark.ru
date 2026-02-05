<?php
declare(strict_types=1);

/**
 * Проверка конкретного поля в задаче
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

$taskId = $argv[1] ?? '1481';
$fieldName = $argv[2] ?? 'autocompleteSubTasks';

$container = new ServiceContainer();
$repo = $container->get('newTaskDetailsRepository');

if ($repo === null) {
    echo "❌ Репозиторий недоступен.\n";
    exit(1);
}

$records = $repo->findByTaskId($taskId, 1);
if (empty($records)) {
    echo "❌ Задача {$taskId} не найдена.\n";
    exit(1);
}

$record = $records[0];
$raw = json_decode($record['raw_payload'], true);

echo "🔍 Проверка поля '{$fieldName}' в задаче {$taskId}\n";
echo str_repeat("=", 80) . "\n";

// Проверка в raw_payload
$value = null;
$foundPath = null;

// Различные варианты путей
$paths = [
    "\$.result.task.{$fieldName}",
    "\$.result.task." . strtoupper($fieldName),
    "\$.result.task." . strtolower($fieldName),
    "\$.result.task." . ucfirst($fieldName),
];

foreach ($paths as $path) {
    $val = $repo->getRawPayloadFieldValue($taskId, $path);
    if ($val !== null) {
        $value = $val;
        $foundPath = $path;
        break;
    }
}

// Прямая проверка в массиве
if ($value === null && isset($raw['result']['task'][$fieldName])) {
    $value = $raw['result']['task'][$fieldName];
    $foundPath = "direct access: result.task.{$fieldName}";
}

if ($value === null && isset($raw['result']['task'][strtoupper($fieldName)])) {
    $value = $raw['result']['task'][strtoupper($fieldName)];
    $foundPath = "direct access: result.task." . strtoupper($fieldName);
}

if ($value !== null) {
    echo "✅ Поле найдено!\n";
    echo "Путь: {$foundPath}\n";
    echo "Значение: " . json_encode($value) . "\n";
    echo "Тип: " . gettype($value) . "\n";
} else {
    echo "❌ Поле '{$fieldName}' не найдено в raw_payload.\n\n";
    
    echo "📋 Все доступные поля в задаче (первые 50):\n";
    echo str_repeat("-", 80) . "\n";
    $taskFields = $raw['result']['task'] ?? [];
    $i = 0;
    foreach ($taskFields as $key => $val) {
        if ($i++ >= 50) {
            echo "... и ещё " . (count($taskFields) - 50) . " полей\n";
            break;
        }
        $display = is_array($val) ? 'array(' . count($val) . ')' : (is_string($val) && strlen($val) > 40 ? substr($val, 0, 40) . '...' : json_encode($val));
        echo sprintf("%-30s: %s\n", $key, $display);
    }
    
    echo "\n💡 Возможные причины:\n";
    echo "   1. Поле не возвращается методом tasks.task.get по умолчанию\n";
    echo "   2. Нужно указать поле в параметре 'select' при запросе\n";
    echo "   3. Поле доступно только в определённых версиях API\n";
    echo "\n📚 Документация: https://apidocs.bitrix24.ru/api-reference/rest-v3/tasks/fields.html\n";
}

echo "\n";
