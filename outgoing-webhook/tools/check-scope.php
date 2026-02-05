<?php
declare(strict_types=1);

/**
 * Проверка scope (прав доступа) приложения Bitrix24
 */

require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

$client = new Bitrix24Client();

echo "Проверка scope приложения Bitrix24...\n\n";

// Проверяем scope
$scopeResult = $client->call('scope');
echo "Scope приложения:\n";
echo json_encode($scopeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Пробуем разные варианты названий методов для получения трудозатрат
echo "Проверка доступных методов для работы с трудозатратами:\n\n";

$methods = [
    'tasks.task.elapseditem.get',
    'tasks.elapseditem.get',
    'tasks.task.elapseditem.list',
    'tasks.elapseditem.list',
    'tasks.task.get',  // Этот точно работает, проверим структуру ответа
];

foreach ($methods as $method) {
    echo "Проверка метода: $method\n";
    
    if ($method === 'tasks.task.get') {
        // Для tasks.task.get используем реальный ID задачи
        $result = $client->call($method, ['id' => 1481]);
    } else {
        // Для остальных пробуем с TASKID
        $result = $client->call($method, ['TASKID' => 1481]);
    }
    
    if (!empty($result['error'])) {
        echo "  ❌ Ошибка: {$result['error']} - {$result['error_information']}\n";
    } else {
        echo "  ✅ Метод доступен!\n";
        if (!empty($result['result'])) {
            echo "  Структура ответа (первые 200 символов):\n";
            $preview = json_encode($result['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            echo "  " . substr($preview, 0, 200) . "...\n";
        }
    }
    echo "\n";
}
