<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';


try {
    // Инициализация контейнера
    $container = new ServiceContainer();
    
    // Логирование для отладки (временное)
    $container->get('errors')->log('index.php: Starting request processing', [
        'timestamp' => date('Y-m-d H:i:s'),
        'script' => __FILE__,
    ]);
    
    // Инициализация компонентов
    $validator = new RequestValidator();
    $auth = new AuthMiddleware(
        $container->get('access'),
        $container->get('config')
    );
    
    // Выбор процессора событий: БД или файловая система
    $useDatabase = $container->get('config')->get('DATABASE_TYPE', 'sqlite') === 'sqlite';
    $processor = null;
    $processorType = 'unknown';
    
    if ($useDatabase) {
        try {
            // Проверка доступности репозиториев
            $hasEventRepo = $container->has('eventRepository');
            $hasQueueRepo = $container->has('queueRepository');
            
            $container->get('errors')->log('Checking database availability', [
                'use_database' => $useDatabase,
                'has_event_repo' => $hasEventRepo,
                'has_queue_repo' => $hasQueueRepo,
            ]);
            
            if ($hasEventRepo && $hasQueueRepo) {
                // Проверяем, что репозитории не null (БД доступна)
                $eventRepo = $container->get('eventRepository');
                $queueRepo = $container->get('queueRepository');
                
                if ($eventRepo !== null && $queueRepo !== null) {
                    $processor = new DatabaseEventProcessor(
                        $container->get('request'),
                        $container->get('identity'),
                        $container->get('filesystem'),
                        $container->get('errors'),
                        $container->get('formatter'),
                        $container->get('config'),
                        $eventRepo,
                        $queueRepo
                    );
                    $processorType = 'DatabaseEventProcessor';
                    // Логирование для отладки
                    $container->get('errors')->log('Using DatabaseEventProcessor', [
                        'processor' => 'DatabaseEventProcessor',
                        'class' => get_class($processor),
                    ]);
                } else {
                    $processorType = 'files (repos null)';
                    $container->get('errors')->log('Repositories are null (DB not available), using files', [
                        'event_repo_null' => $eventRepo === null,
                        'queue_repo_null' => $queueRepo === null,
                    ]);
                }
            } else {
                $processorType = 'files (repos not registered)';
                $container->get('errors')->log('Repositories not registered, using files', [
                    'has_event_repo' => $hasEventRepo,
                    'has_queue_repo' => $hasQueueRepo,
                ]);
            }
        } catch (Throwable $e) {
            $processorType = 'error: ' . $e->getMessage();
            $fallbackEnabled = strtolower($container->get('config')->get('DATABASE_FALLBACK_TO_FILES', 'false')) === 'true';
            
            if ($fallbackEnabled) {
                $container->get('errors')->log('Failed to use DatabaseEventProcessor, falling back to files', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } else {
                // Строгий режим: логируем ошибку, но не создаем процессор
                $container->get('errors')->log('Failed to use DatabaseEventProcessor, fallback disabled', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }
    } else {
        $processorType = 'files (config)';
    }
    
    // Если БД недоступна, проверяем настройку fallback
    if ($processor === null) {
        $fallbackEnabled = strtolower($container->get('config')->get('DATABASE_FALLBACK_TO_FILES', 'false')) === 'true';
        
        if ($fallbackEnabled) {
            $processor = new EventProcessor(
                $container->get('request'),
                $container->get('identity'),
                $container->get('filesystem'),
                $container->get('errors'),
                $container->get('formatter'),
                $container->get('config')
            );
            $container->get('errors')->log('Using EventProcessor (files fallback)', [
                'processor' => 'EventProcessor',
                'reason' => $processorType,
            ]);
        } else {
            // Строгий режим: БД обязательна, fallback отключен
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Database unavailable',
                'message' => 'SQLite database is required but not available. Fallback to files is disabled.',
                'processor_type' => $processorType,
            ], JSON_PRETTY_PRINT);
            exit(1);
        }
    }
    $taskHandler = new TaskEventHandler(
        $container->get('rest'),
        $container->get('taskDetails'),
        $container->get('errors')
    );
    $commentHandler = new CommentEventHandler(
        $container->get('commentDetails'),
        $container->get('rest'),
        $container->get('taskDetails'),
        $container->get('identity'),
        $container->get('errors'),
        $container->get('config')
    );
    $dealHandler = new DealEventHandler(
        $container->get('rest'),
        $container->get('stateStorage'),
        $container->get('errors')
    );

    // Валидация запроса
    $validator->validateMethod();
    $validator->validateContentLength();
    
    $payload = $container->get('request')->readPayload();
    $validator->validatePayload($payload);

    // Аутентификация
    $requestId = $container->get('request')->generateRequestId();
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $auth->authenticate($payload, $clientIp);
    $authInfo = $auth->extractAuthInfo($payload);

    // Обработка события
    $eventData = $processor->process($payload, $requestId, $clientIp, $authInfo);

    // Специальная обработка задач
    $taskHandler->handle(
        $eventData['eventType'],
        $eventData['entityId'],
        $requestId
    );

    // Специальная обработка комментариев
    $needsSync = $commentHandler->handle(
        $eventData['eventType'],
        $payload,
        $eventData['entityId'],
        $requestId,
        $eventData['rawPath']
    );

    // Обработка сделок: загрузка данных + трекинг изменений (что было → что стало)
    $dealHandler->handle(
        $eventData['eventType'],
        $eventData['entityId'],
        $requestId
    );

    // Синхронная обработка Activity (если требуется)
    if ($needsSync) {
        $lastDetails = $commentHandler->getLastDetails();
        outgoingWebhookJsonResponse(200, ['status' => 'ok']);
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            // Обработка после отправки ответа
            if ($lastDetails !== null) {
                outgoingWebhookProcessActivitySync(
                    $lastDetails,
                    $eventData['entityId'],
                    $requestId
                );
            }
        } else {
            register_shutdown_function(function() use ($lastDetails, $eventData, $requestId) {
                if ($lastDetails !== null) {
                    outgoingWebhookProcessActivitySync(
                        $lastDetails,
                        $eventData['entityId'],
                        $requestId
                    );
                }
            });
        }
        exit;
    }

    outgoingWebhookJsonResponse(200, ['status' => 'ok']);
} catch (InvalidRequestException $e) {
    outgoingWebhookJsonResponse($e->getCode(), ['error' => $e->getMessage()]);
} catch (AuthenticationException $e) {
    outgoingWebhookJsonResponse($e->getCode(), ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    outgoingWebhookLogError('Unexpected error', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    outgoingWebhookJsonResponse(500, ['error' => 'internal_server_error']);
}
