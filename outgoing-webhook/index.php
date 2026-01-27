<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use InvalidRequestException;
use AuthenticationException;

try {
    // Инициализация контейнера
    $container = new ServiceContainer();
    
    // Инициализация компонентов
    $validator = new RequestValidator();
    $auth = new AuthMiddleware(
        $container->get('access'),
        $container->get('config')
    );
    $processor = new EventProcessor(
        $container->get('request'),
        $container->get('identity'),
        $container->get('filesystem'),
        $container->get('errors'),
        $container->get('formatter')
    );
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

    // Синхронная обработка ActivityFirst (если требуется)
    if ($needsSync) {
        $lastDetails = $commentHandler->getLastDetails();
        outgoingWebhookJsonResponse(200, ['status' => 'ok']);
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            // Обработка после отправки ответа
            if ($lastDetails !== null) {
                outgoingWebhookProcessActivityFirstSync(
                    $lastDetails,
                    $eventData['entityId'],
                    $requestId
                );
            }
        } else {
            register_shutdown_function(function() use ($lastDetails, $eventData, $requestId) {
                if ($lastDetails !== null) {
                    outgoingWebhookProcessActivityFirstSync(
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
