<?php
declare(strict_types=1);

/**
 * Утилита для управления таймером задачи в Bitrix24
 * 
 * Использование:
 *   php task-timer-control.php start 1481    # Запустить таймер
 *   php task-timer-control.php stop 1481     # Остановить таймер
 *   php task-timer-control.php status 1481   # Проверить статус таймера
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

$action = $argv[1] ?? null;
$taskId = isset($argv[2]) ? (int)$argv[2] : null;

if (!$action || !$taskId) {
    echo "Использование: php task-timer-control.php <start|stop|status> <task_id>\n";
    echo "Пример: php task-timer-control.php start 1481\n";
    exit(1);
}

$client = new Bitrix24Client();

/**
 * Проверка статуса таймера задачи
 * 
 * ВАЖНО: Методы tasks.task.elapseditem.* недоступны в REST API.
 * Используем поле timeSpentInLogs для определения статуса таймера.
 */
function checkTimerStatus(int $taskId, Bitrix24Client $client): array
{
    // Получаем задачу со всеми полями
    $taskResult = $client->call('tasks.task.get', [
        'id' => $taskId,
        'select' => ['*']
    ]);
    
    if (!empty($taskResult['error'])) {
        return [
            'success' => false,
            'error' => $taskResult['error'],
            'error_information' => $taskResult['error_information'] ?? '',
            'has_active_timer' => false
        ];
    }
    
    $task = $taskResult['result']['task'] ?? $taskResult['result'] ?? null;
    
    if (!$task) {
        return [
            'success' => false,
            'error' => 'task_not_found',
            'error_information' => 'Задача не найдена',
            'has_active_timer' => false
        ];
    }
    
    // Проверяем поле timeSpentInLogs
    // Если оно не null и больше 0, значит время учитывается
    $timeSpentInLogs = $task['timeSpentInLogs'] ?? null;
    $allowTimeTracking = ($task['allowTimeTracking'] ?? 'N') === 'Y';
    $timeEstimate = (int)($task['timeEstimate'] ?? 0);
    
    // Определяем статус таймера
    // Если timeSpentInLogs не null, возможно таймер активен или был активен
    // Но точно определить активный таймер без методов elapseditem.* нельзя
    $hasActiveTimer = false;
    $timerInfo = null;
    
    if ($timeSpentInLogs !== null && $timeSpentInLogs !== '') {
        // Есть данные о затраченном времени
        $timerInfo = [
            'timeSpentInLogs' => $timeSpentInLogs,
            'timeEstimate' => $timeEstimate,
            'allowTimeTracking' => $allowTimeTracking,
        ];
        
        // Невозможно точно определить, активен ли таймер сейчас
        // без методов elapseditem.*, которые недоступны в REST API
        // Предполагаем, что если время учитывается, таймер может быть активен
    }
    
    return [
        'success' => true,
        'has_active_timer' => $hasActiveTimer,  // Не можем точно определить
        'time_spent_in_logs' => $timeSpentInLogs,
        'time_estimate' => $timeEstimate,
        'allow_time_tracking' => $allowTimeTracking,
        'timer_info' => $timerInfo,
        'task_status' => $task['status'] ?? null,
        'task_title' => $task['title'] ?? null,
        'note' => 'Методы tasks.task.elapseditem.* недоступны в REST API. ' .
                  'Точное определение активного таймера невозможно без этих методов. ' .
                  'Используйте поле timeSpentInLogs для отслеживания изменений времени.'
    ];
}

/**
 * Запуск таймера задачи
 */
function startTaskTimer(int $taskId, Bitrix24Client $client): array
{
    // 1. Проверяем, нет ли уже активного таймера
    $status = checkTimerStatus($taskId, $client);
    
    if (!$status['success']) {
        // Если метод недоступен, но есть данные задачи, пробуем запустить таймер
        if ($status['error'] === 'METHOD_NOT_AVAILABLE') {
            // Продолжаем попытку запуска, возможно метод add работает
        } else {
            return $status;
        }
    }
    
    if ($status['has_active_timer']) {
        return [
            'success' => false,
            'error' => 'timer_already_running',
            'error_information' => 'Таймер уже запущен для этой задачи',
            'active_item_id' => $status['active_item']['ID'] ?? null
        ];
    }
    
    // 2. Получаем информацию о задаче (для проверки прав и статуса)
    $taskResult = $client->call('tasks.task.get', [
        'id' => $taskId
    ]);
    
    if (!empty($taskResult['error'])) {
        return [
            'success' => false,
            'error' => $taskResult['error'],
            'error_information' => $taskResult['error_information'] ?? ''
        ];
    }
    
    $task = $taskResult['result']['task'] ?? $taskResult['result'] ?? null;
    if (!$task) {
        return [
            'success' => false,
            'error' => 'task_not_found',
            'error_information' => 'Задача не найдена'
        ];
    }
    
    // 3. Запускаем таймер через добавление записи трудозатрат
    // Пробуем разные варианты названий метода
    $addMethods = [
        'tasks.task.elapseditem.add',
        'tasks.elapseditem.add',
    ];
    
    $addResult = null;
    $usedMethod = null;
    
    foreach ($addMethods as $method) {
        $result = $client->call($method, [
            'TASKID' => $taskId,
            'FIELDS' => [
                'COMMENT_TEXT' => 'Таймер запущен через API',
                'DATE_START' => date('Y-m-d H:i:s'),
                // DATE_STOP не указываем - это создаст активный таймер
            ]
        ]);
        
        if (empty($result['error'])) {
            $addResult = $result;
            $usedMethod = $method;
            break;
        }
    }
    
    if ($addResult === null) {
        return [
            'success' => false,
            'error' => 'METHOD_NOT_AVAILABLE',
            'error_information' => 'Методы для добавления трудозатрат недоступны. Проверьте scope приложения.',
            'tried_methods' => $addMethods
        ];
    }
    
    if (!empty($addResult['error'])) {
        return [
            'success' => false,
            'error' => $addResult['error'],
            'error_information' => $addResult['error_information'] ?? ''
        ];
    }
    
    $itemId = $addResult['result'] ?? null;
    
    return [
        'success' => true,
        'item_id' => $itemId,
        'task_id' => $taskId,
        'task_title' => $task['title'] ?? 'N/A',
        'task_status' => $task['status'] ?? 'N/A',
        'message' => 'Таймер успешно запущен',
        'used_method' => $usedMethod
    ];
}

/**
 * Остановка таймера задачи
 * 
 * ВАЖНО: Прямое управление таймером через REST API недоступно.
 * Методы tasks.task.elapseditem.* не экспортированы в REST API.
 */
function stopTaskTimer(int $taskId, Bitrix24Client $client): array
{
    // 1. Проверяем текущий статус задачи
    $status = checkTimerStatus($taskId, $client);
    
    if (!$status['success']) {
        return $status;
    }
    
    // 2. Получаем информацию о задаче
    $taskResult = $client->call('tasks.task.get', [
        'id' => $taskId
    ]);
    
    if (!empty($taskResult['error'])) {
        return [
            'success' => false,
            'error' => $taskResult['error'],
            'error_information' => $taskResult['error_information'] ?? ''
        ];
    }
    
    $task = $taskResult['result']['task'] ?? $taskResult['result'] ?? null;
    if (!$task) {
        return [
            'success' => false,
            'error' => 'task_not_found',
            'error_information' => 'Задача не найдена'
        ];
    }
    
    $timeSpentInLogs = $task['timeSpentInLogs'] ?? null;
    
    // 3. Пробуем методы для обновления трудозатрат (скорее всего не сработают)
    $updateMethods = [
        'tasks.task.elapseditem.update',
        'tasks.elapseditem.update',
    ];
    
    // Но у нас нет ID активной записи, так как методы get недоступны
    // Поэтому пробуем косвенный способ через изменение статуса
    
    $currentStatus = (int)($task['status'] ?? 0);
    
    // Если статус "Выполняется", можно попробовать изменить его
    if ($currentStatus === 3) {
        // Меняем статус на другой (например, "Новая" или "Ждет выполнения")
        $updateResult = $client->call('tasks.task.update', [
            'id' => $taskId,
            'fields' => [
                'status' => 2  // Статус "Новая" или другой неактивный статус
            ]
        ]);
        
        if (!empty($updateResult['error'])) {
            return [
                'success' => false,
                'error' => 'cannot_stop_timer',
                'error_information' => 'Не удалось остановить таймер. Методы tasks.task.elapseditem.* недоступны в REST API. ' .
                                      'Используйте UI Bitrix24 для остановки таймера.',
                'tried_methods' => $updateMethods,
                'status_update_error' => $updateResult['error']
            ];
        }
        
        return [
            'success' => false,
            'error' => 'METHOD_NOT_AVAILABLE',
            'error_information' => 'Методы для управления таймером недоступны в REST API. ' .
                                  'Статус задачи изменен, но таймер нужно остановить вручную через UI Bitrix24.',
            'status_changed' => true,
            'new_status' => 2,
            'time_spent_in_logs' => $timeSpentInLogs,
            'note' => 'Для управления таймером используйте UI Bitrix24 или отслеживайте изменения через вебхуки ONTASKUPDATE'
        ];
    }
    
    return [
        'success' => false,
        'error' => 'METHOD_NOT_AVAILABLE',
        'error_information' => 'Методы для управления таймером недоступны в REST API. ' .
                              'Используйте UI Bitrix24 для остановки таймера.',
        'tried_methods' => $updateMethods,
        'current_status' => $currentStatus,
        'time_spent_in_logs' => $timeSpentInLogs
    ];
}

// Выполнение действия
try {
    switch ($action) {
        case 'start':
            $result = startTaskTimer($taskId, $client);
            break;
            
        case 'stop':
            $result = stopTaskTimer($taskId, $client);
            break;
            
        case 'status':
            $result = checkTimerStatus($taskId, $client);
            break;
            
        default:
            echo "Неизвестное действие: $action\n";
            echo "Доступные действия: start, stop, status\n";
            exit(1);
    }
    
    // Вывод результата
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    if ($result['success'] ?? false) {
        exit(0);
    } else {
        exit(1);
    }
    
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'exception',
        'error_information' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
