<?php
declare(strict_types=1);

/**
 * Процессор событий с использованием БД
 * 
 * Ответственность:
 * - Извлечение данных события
 * - Создание задачи в очереди (в БД)
 * - Логирование события (в БД)
 * 
 * Наследует функциональность от EventProcessor, но использует БД вместо файлов
 */
class DatabaseEventProcessor extends EventProcessor
{
    private EventRepository $eventRepository;
    private QueueRepository $queueRepository;

    public function __construct(
        RequestService $request,
        EntityIdentityService $identity,
        FilesystemService $filesystem,
        ErrorService $errors,
        LogValueFormatter $formatter,
        ConfigService $config,
        EventRepository $eventRepository,
        QueueRepository $queueRepository
    ) {
        parent::__construct($request, $identity, $filesystem, $errors, $formatter, $config);
        $this->eventRepository = $eventRepository;
        $this->queueRepository = $queueRepository;
    }

    /**
     * Переопределение метода логирования для записи в БД
     */
    protected function logEvent(
        string $eventType,
        string $entityType,
        ?string $entityId,
        string $requestId,
        string $clientIp,
        array $authInfo,
        array $payload
    ): string {
        // Логирование для отладки
        $this->errors->log('DatabaseEventProcessor::logEvent called', [
            'request_id' => $requestId,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
        
        $maskedPayload = $this->formatter->maskPayload($payload);
        
        // Подготовка данных для записи в БД
        $eventData = [
            'requestId' => $requestId,
            'eventType' => $eventType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'receivedAt' => $this->request->now(),
            'ip' => $clientIp,
            'tokenSource' => $authInfo['source'],
            'eventHandlerId' => (string) ($payload['event_handler_id'] ?? 'unknown'),
            'memberId' => (string) ($payload['auth']['member_id'] ?? 'unknown'),
            'payload' => $maskedPayload,
            'createdAt' => $this->request->now(),
        ];

        // Запись события в БД
        $eventId = $this->eventRepository->create($eventData);
        
        if ($eventId === null) {
            $this->errors->log('Failed to save event to database', [
                'request_id' => $requestId,
                'event_type' => $eventType,
            ]);
            // Возвращаем путь для обратной совместимости даже при ошибке
            return "db://events/failed";
        }

        $this->errors->log('Event saved to database successfully', [
            'request_id' => $requestId,
            'event_id' => $eventId,
            'event_type' => $eventType,
        ]);

        // Возвращаем путь к записи в БД для обратной совместимости
        // (используется в других частях системы)
        return "db://events/{$eventId}";
    }

    /**
     * Переопределение метода создания задачи в очереди для записи в БД
     * 
     * Важно: Событие уже должно быть создано в logEvent(), так как queue_jobs имеет FOREIGN KEY на events
     */
    protected function createQueueItem(
        string $requestId,
        string $eventType,
        string $entityType,
        ?string $entityId,
        array $authInfo,
        array $payload,
        string $rawPath
    ): array {
        $maskedPayload = $this->formatter->maskPayload($payload);
        
        // Проверяем, что событие существует (оно должно быть создано в logEvent())
        $existingEvent = $this->eventRepository->findByRequestId($requestId);
        if ($existingEvent === null) {
            // Если событие не создано, создаем его сейчас (fallback)
            $this->errors->log('Event not found when creating queue job, creating it now', [
                'request_id' => $requestId,
                'event_type' => $eventType,
            ]);
            
            // Создаем минимальное событие для внешнего ключа
            $minimalEventData = [
                'requestId' => $requestId,
                'eventType' => $eventType,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'receivedAt' => $this->request->now(),
                'ip' => null,
                'tokenSource' => $authInfo['source'],
                'eventHandlerId' => (string) ($payload['event_handler_id'] ?? 'unknown'),
                'memberId' => (string) ($payload['auth']['member_id'] ?? 'unknown'),
                'payload' => $maskedPayload,
                'createdAt' => $this->request->now(),
            ];
            
            $eventId = $this->eventRepository->create($minimalEventData);
            if ($eventId === null) {
                $this->errors->log('Failed to create event for queue job', [
                    'request_id' => $requestId,
                ]);
            }
        }
        
        // Создание задачи в очереди (только если очередь включена)
        $queueEnabled = strtolower($this->config->get('QUEUE_ENABLED', 'false')) === 'true';
        
        if (!$queueEnabled) {
            // Очередь отключена, возвращаем null
            return null;
        }
        
        // Подготовка данных для записи в очередь БД
        $jobData = [
            'requestId' => $requestId,
            'eventType' => $eventType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'status' => 'pending',
            'attempt' => 0,
            'priority' => 'normal',
            'source' => 'outgoing-webhook',
            'tokenSource' => $authInfo['source'],
            'eventHandlerId' => (string) ($payload['event_handler_id'] ?? 'unknown'),
            'memberId' => (string) ($payload['auth']['member_id'] ?? 'unknown'),
            'payload' => $maskedPayload,
            'createdAt' => $this->request->now(),
        ];

        // Запись задания в очередь БД
        $jobId = $this->queueRepository->createJob($jobData);
        
        if ($jobId === null) {
            $this->errors->log('Failed to create queue job in database', [
                'request_id' => $requestId,
                'event_type' => $eventType,
            ]);
        }

        // Возвращаем данные задания для обратной совместимости
        return array_merge($jobData, [
            'id' => $jobId,
            'rawPath' => $rawPath,
        ]);
    }
}
