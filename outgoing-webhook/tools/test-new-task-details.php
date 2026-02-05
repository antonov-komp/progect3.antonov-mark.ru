<?php
declare(strict_types=1);

/**
 * Тестовый скрипт для проверки работы с new_task_details
 * 
 * Использование: php test-new-task-details.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

$container = new ServiceContainer();

// Проверка доступности репозитория
if (!$container->has('newTaskDetailsRepository')) {
    echo "❌ Репозиторий newTaskDetailsRepository не найден в контейнере.\n";
    exit(1);
}

$repo = $container->get('newTaskDetailsRepository');
if ($repo === null) {
    echo "❌ Репозиторий newTaskDetailsRepository вернул null (БД недоступна).\n";
    exit(1);
}

echo "✓ Репозиторий newTaskDetailsRepository доступен.\n\n";

// 1. Проверка количества записей
try {
    $db = $container->get('database');
    $countResult = $db->queryOne("SELECT COUNT(*) as count FROM new_task_details");
    $count = (int) ($countResult['count'] ?? 0);
    echo "📊 Всего записей в new_task_details: {$count}\n\n";
} catch (Throwable $e) {
    echo "⚠️  Ошибка при подсчёте записей: {$e->getMessage()}\n\n";
    $count = 0;
}

if ($count === 0) {
    echo "ℹ️  В таблице пока нет записей. Создайте задачу в Bitrix24 для тестирования.\n";
    echo "   После создания задачи (ONTASKADD) здесь появится запись с полным снимком.\n\n";
    exit(0);
}

// 2. Получить последние 5 записей
echo "📋 Последние 5 записей:\n";
echo str_repeat("=", 80) . "\n";
try {
    $recent = $db->queryAll(
        "SELECT id, request_id, event_type, task_id, created_at, 
                LENGTH(raw_payload) as raw_size, 
                LENGTH(extracted) as extracted_size
         FROM new_task_details 
         ORDER BY created_at DESC 
         LIMIT 5"
    );

    foreach ($recent as $i => $row) {
        echo sprintf(
            "%d. ID: %d | Task: %s | Event: %s | Request: %s | Created: %s\n",
            $i + 1,
            $row['id'],
            $row['task_id'],
            $row['event_type'],
            substr($row['request_id'], 0, 8) . '...',
            $row['created_at']
        );
        echo sprintf(
            "   Raw payload: %d bytes | Extracted: %d bytes\n",
            $row['raw_size'],
            $row['extracted_size']
        );
    }
} catch (Throwable $e) {
    echo "❌ Ошибка при получении записей: {$e->getMessage()}\n";
}

echo "\n";

// 3. Примеры извлечения полей из extracted
if ($count > 0) {
    echo "🔍 Примеры извлечения полей:\n";
    echo str_repeat("=", 80) . "\n";
    
    // Получить первую запись
    $first = $db->queryOne("SELECT * FROM new_task_details ORDER BY created_at DESC LIMIT 1");
    if ($first) {
        $extracted = json_decode($first['extracted'], true);
        $taskId = $first['task_id'];
        
        echo "Task ID: {$taskId}\n";
        echo "\nИзвлечённые поля (extracted):\n";
        echo "- title: " . ($extracted['title'] ?? 'не указано') . "\n";
        echo "- responsibleId: " . ($extracted['responsibleId'] ?? 'не указано') . "\n";
        echo "- status: " . ($extracted['status'] ?? 'не указано') . "\n";
        echo "- priority: " . ($extracted['priority'] ?? 'не указано') . "\n";
        echo "- createdBy: " . ($extracted['createdBy'] ?? 'не указано') . "\n";
        echo "- groupId: " . ($extracted['groupId'] ?? 'не указано') . "\n";
        echo "- stageId: " . ($extracted['stageId'] ?? 'не указано') . "\n";
        
        if (!empty($extracted['dates'])) {
            echo "\nДаты:\n";
            foreach ($extracted['dates'] as $key => $value) {
                if ($value) {
                    echo "- {$key}: {$value}\n";
                }
            }
        }
        
        if (!empty($extracted['crmLinks'])) {
            echo "\nCRM связи: " . count($extracted['crmLinks']) . " шт.\n";
        }
        
        if (!empty($extracted['files'])) {
            echo "\nФайлы: " . count($extracted['files']) . " шт.\n";
        }
        
        echo "\n";
    }
}

// 4. Примеры запросов по условиям
echo "📝 Примеры запросов по условиям:\n";
echo str_repeat("=", 80) . "\n";

if ($count > 0) {
    $firstTaskId = $db->queryOne("SELECT task_id FROM new_task_details LIMIT 1")['task_id'] ?? null;
    
    if ($firstTaskId) {
        echo "\n1. Найти запись по task_id:\n";
        echo "   \$repo->findByTaskId('{$firstTaskId}');\n";
        
        $found = $repo->findByTaskId($firstTaskId, 1);
        if (!empty($found)) {
            echo "   ✓ Найдено записей: " . count($found) . "\n";
        }
        
        echo "\n2. Найти записи по типу события:\n";
        echo "   \$repo->findByEventType('ONTASKADD');\n";
        
        $found = $repo->findByEventType('ONTASKADD', 5);
        echo "   ✓ Найдено записей: " . count($found) . "\n";
        
        echo "\n3. Получить значение поля из extracted:\n";
        echo "   \$repo->getExtractedFieldValue('{$firstTaskId}', 'title');\n";
        
        $title = $repo->getExtractedFieldValue($firstTaskId, 'title');
        if ($title !== null) {
            echo "   ✓ Значение: " . (is_string($title) ? $title : json_encode($title)) . "\n";
        } else {
            echo "   ⚠️  Поле не найдено\n";
        }
        
        echo "\n4. Найти задачи по полю в extracted (например, по responsibleId):\n";
        echo "   \$repo->findByExtractedField('responsibleId', '123');\n";
        echo "   (показывает пример, реальный ID может отличаться)\n";
        
        // Попробуем найти по любому responsibleId из первой записи
        if (!empty($found)) {
            $firstFound = $found[0];
            $extracted = json_decode($firstFound['extracted'], true);
            $responsibleId = $extracted['responsibleId'] ?? null;
            
            if ($responsibleId) {
                echo "\n   Попробуем найти по responsibleId = '{$responsibleId}':\n";
                $byResponsible = $repo->findByExtractedField('responsibleId', (string)$responsibleId, '=', 5);
                echo "   ✓ Найдено записей: " . count($byResponsible) . "\n";
            }
        }
        
        echo "\n5. Получить значение из raw_payload (JSONPath):\n";
        echo "   \$repo->getRawPayloadFieldValue('{$firstTaskId}', '\$.result.task.TITLE');\n";
        
        $rawTitle = $repo->getRawPayloadFieldValue($firstTaskId, '$.result.task.TITLE');
        if ($rawTitle !== null) {
            echo "   ✓ Значение из raw: " . (is_string($rawTitle) ? $rawTitle : json_encode($rawTitle)) . "\n";
        } else {
            // Попробуем другой путь
            $rawTitle2 = $repo->getRawPayloadFieldValue($firstTaskId, '$.result.task.title');
            if ($rawTitle2 !== null) {
                echo "   ✓ Значение из raw (альтернативный путь): " . (is_string($rawTitle2) ? $rawTitle2 : json_encode($rawTitle2)) . "\n";
            } else {
                echo "   ⚠️  Поле не найдено (возможно, другой путь в JSON)\n";
            }
        }
    }
}

echo "\n";
echo str_repeat("=", 80) . "\n";
echo "✅ Проверка завершена.\n";
echo "\n";
echo "💡 Полезные методы репозитория:\n";
echo "   - findByRequestId(\$requestId) - найти по request_id\n";
echo "   - findByTaskId(\$taskId, \$limit) - найти по task_id\n";
echo "   - findByEventType(\$eventType, \$limit) - найти по типу события\n";
echo "   - findByExtractedField(\$field, \$value, \$operator, \$limit) - найти по полю в extracted\n";
echo "   - getExtractedFieldValue(\$taskId, \$field) - получить значение поля из extracted\n";
echo "   - getRawPayloadFieldValue(\$taskId, \$jsonPath) - получить значение из raw_payload по JSONPath\n";
echo "\n";
