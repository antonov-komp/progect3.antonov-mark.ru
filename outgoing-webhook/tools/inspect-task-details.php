<?php
declare(strict_types=1);

/**
 * Скрипт для детального просмотра структуры данных задачи
 * 
 * Использование: php inspect-task-details.php [task_id]
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

$container = new ServiceContainer();
$repo = $container->get('newTaskDetailsRepository');

if ($repo === null) {
    echo "❌ Репозиторий недоступен.\n";
    exit(1);
}

// Получить task_id из аргументов или взять последний
$taskId = $argv[1] ?? null;

if ($taskId === null) {
    $db = $container->get('database');
    $last = $db->queryOne("SELECT task_id FROM new_task_details ORDER BY created_at DESC LIMIT 1");
    $taskId = $last['task_id'] ?? null;
    
    if ($taskId === null) {
        echo "❌ Нет записей в таблице.\n";
        exit(1);
    }
    
    echo "ℹ️  Используется последняя задача: {$taskId}\n\n";
}

// Получить запись
$records = $repo->findByTaskId($taskId, 1);
if (empty($records)) {
    echo "❌ Задача {$taskId} не найдена.\n";
    exit(1);
}

$record = $records[0];

echo "📋 Детали задачи ID: {$taskId}\n";
echo str_repeat("=", 80) . "\n";
echo "Request ID: {$record['request_id']}\n";
echo "Event Type: {$record['event_type']}\n";
echo "Created At: {$record['created_at']}\n";
echo "\n";

// Показать extracted
echo "📦 Извлечённые поля (extracted):\n";
echo str_repeat("-", 80) . "\n";
$extracted = $record['extracted_decoded'] ?? json_decode($record['extracted'], true) ?? [];
echo json_encode($extracted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n\n";

// Показать структуру raw_payload
echo "🔍 Структура raw_payload (первые 2000 символов):\n";
echo str_repeat("-", 80) . "\n";
$raw = $record['raw_payload_decoded'] ?? json_decode($record['raw_payload'], true) ?? [];

if (!empty($raw)) {
    // Показать ключи верхнего уровня
    echo "Ключи верхнего уровня:\n";
    foreach (array_keys($raw) as $key) {
        echo "  - {$key}\n";
    }
    echo "\n";
    
    // Показать структуру result, если есть
    if (isset($raw['result'])) {
        echo "Структура result:\n";
        if (is_array($raw['result'])) {
            foreach (array_keys($raw['result']) as $key) {
                $value = $raw['result'][$key];
                $type = is_array($value) ? 'array(' . count($value) . ')' : gettype($value);
                echo "  - {$key}: {$type}\n";
            }
        }
        echo "\n";
        
        // Показать структуру task, если есть
        if (isset($raw['result']['task']) && is_array($raw['result']['task'])) {
            echo "Ключи в result.task (первые 30):\n";
            $taskKeys = array_slice(array_keys($raw['result']['task']), 0, 30);
            foreach ($taskKeys as $key) {
                $value = $raw['result']['task'][$key];
                if (is_array($value)) {
                    $preview = 'array(' . count($value) . ')';
                } elseif (is_string($value) && strlen($value) > 50) {
                    $preview = substr($value, 0, 50) . '...';
                } else {
                    $preview = is_scalar($value) ? (string)$value : gettype($value);
                }
                echo "  - {$key}: {$preview}\n";
            }
            if (count($raw['result']['task']) > 30) {
                echo "  ... и ещё " . (count($raw['result']['task']) - 30) . " полей\n";
            }
        }
    }
    
    echo "\n";
    echo "Полный raw_payload (первые 2000 символов JSON):\n";
    $rawJson = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo substr($rawJson, 0, 2000);
    if (strlen($rawJson) > 2000) {
        echo "\n... (обрезано, всего " . strlen($rawJson) . " символов)\n";
    }
} else {
    echo "⚠️  Не удалось декодировать raw_payload\n";
}

echo "\n";
echo str_repeat("=", 80) . "\n";

// Проверить извлечение конкретных полей
echo "🧪 Проверка извлечения полей:\n";
echo str_repeat("-", 80) . "\n";

$testFields = [
    'title',
    'TITLE',
    'responsibleId',
    'RESPONSIBLE_ID',
    'status',
    'STATUS',
    'description',
    'DESCRIPTION',
];

foreach ($testFields as $field) {
    $value = $repo->getExtractedFieldValue($taskId, $field);
    if ($value !== null) {
        $display = is_string($value) && strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
        echo "✓ extracted.{$field}: " . json_encode($display) . "\n";
    }
}

// Проверить JSONPath в raw_payload
echo "\nПроверка JSONPath в raw_payload:\n";
$jsonPaths = [
    '$.result.task.TITLE',
    '$.result.task.title',
    '$.result.task.RESPONSIBLE_ID',
    '$.result.task.responsibleId',
    '$.result.task.STATUS',
    '$.result.task.status',
];

foreach ($jsonPaths as $path) {
    $value = $repo->getRawPayloadFieldValue($taskId, $path);
    if ($value !== null) {
        $display = is_string($value) && strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
        echo "✓ {$path}: " . json_encode($display) . "\n";
    }
}

echo "\n";
