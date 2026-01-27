<?php
declare(strict_types=1);

/**
 * Процессор событий
 * 
 * Ответственность:
 * - Извлечение данных события
 * - Создание задачи в очереди
 * - Логирование события
 */
class EventProcessor
{
    private RequestService $request;
    private EntityIdentityService $identity;
    private FilesystemService $filesystem;
    private ErrorService $errors;
    private LogValueFormatter $formatter;
    private string $basePath;

    public function __construct(
        RequestService $request,
        EntityIdentityService $identity,
        FilesystemService $filesystem,
        ErrorService $errors,
        LogValueFormatter $formatter
    ) {
        $this->request = $request;
        $this->identity = $identity;
        $this->filesystem = $filesystem;
        $this->errors = $errors;
        $this->formatter = $formatter;
        $this->basePath = dirname(__DIR__, 2);
    }

    public function process(array $payload, string $requestId, string $clientIp, array $authInfo): array
    {
        $eventType = $this->request->normalizeEventType(
            (string) ($payload['event'] ?? ($payload['eventName'] ?? ''))
        );
        
        $entityId = $this->identity->normalizeEntityId(
            $this->identity->extractEntityId($payload)
        );
        
        if (str_starts_with($eventType, 'ONTASKCOMMENT') && $entityId === null) {
            $entityId = $this->identity->normalizeEntityId(
                $this->identity->extractTaskId($payload)
            );
        }
        
        $entityType = $this->identity->resolveEntityType($eventType);
        
        // Логирование
        $rawPath = $this->logEvent($eventType, $entityType, $entityId, $requestId, $clientIp, $authInfo, $payload);
        
        // Создание задачи в очереди
        $queueItem = $this->createQueueItem(
            $requestId,
            $eventType,
            $entityType,
            $entityId,
            $authInfo,
            $payload,
            $rawPath
        );
        
        return [
            'eventType' => $eventType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'queueItem' => $queueItem,
            'rawPath' => $rawPath,
        ];
    }

    private function logEvent(
        string $eventType,
        string $entityType,
        ?string $entityId,
        string $requestId,
        string $clientIp,
        array $authInfo,
        array $payload
    ): string {
        $eventDir = $this->basePath . '/logs/' . $eventType;
        $this->filesystem->ensureDir($eventDir);

        $maskedPayload = $this->formatter->maskPayload($payload);
        $raw = [
            'requestId' => $requestId,
            'eventType' => $eventType,
            'receivedAt' => $this->request->now(),
            'ip' => $clientIp,
            'tokenSource' => $authInfo['source'],
            'eventHandlerId' => (string) ($payload['event_handler_id'] ?? 'unknown'),
            'memberId' => (string) ($payload['auth']['member_id'] ?? 'unknown'),
            'payload' => $maskedPayload,
        ];

        $rawPath = $eventDir . '/raw.json';
        if (!$this->filesystem->writeJson($rawPath, $raw)) {
            $this->errors->log('Failed to write raw.json', ['path' => $rawPath]);
        }

        $eventLogPath = $eventDir . '/event.log';
        $eventLogLine = sprintf(
            '%s | IP=%s | requestId=%s | event=%s | entityType=%s | entityId=%s | eventHandlerId=%s | memberId=%s | tokenSource=%s',
            $this->request->now(),
            $clientIp,
            $requestId,
            $eventType,
            $entityType,
            $entityId ?? 'unknown',
            $payload['event_handler_id'] ?? 'unknown',
            $payload['auth']['member_id'] ?? 'unknown',
            $authInfo['source']
        );
        if (!$this->filesystem->appendLine($eventLogPath, $eventLogLine)) {
            $this->errors->log('Failed to write event.log', ['path' => $eventLogPath]);
        }

        return $rawPath;
    }

    private function createQueueItem(
        string $requestId,
        string $eventType,
        string $entityType,
        ?string $entityId,
        array $authInfo,
        array $payload,
        string $rawPath
    ): array {
        $maskedPayload = $this->formatter->maskPayload($payload);
        $queueItem = [
            'requestId' => $requestId,
            'eventType' => $eventType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'rawPath' => $rawPath,
            'createdAt' => $this->request->now(),
            'attempt' => 0,
            'priority' => 'normal',
            'source' => 'outgoing-webhook',
            'tokenSource' => $authInfo['source'],
            'eventHandlerId' => (string) ($payload['event_handler_id'] ?? 'unknown'),
            'memberId' => (string) ($payload['auth']['member_id'] ?? 'unknown'),
            'payload' => $maskedPayload,
        ];

        $queueName = sprintf(
            '%s_%s_%s.json',
            date('Ymd_His'),
            $eventType,
            $entityId ?? 'unknown'
        );
        $queuePath = $this->basePath . '/queue/pending/' . $queueName;
        
        if (!$this->filesystem->writeJson($queuePath, $queueItem)) {
            $this->errors->log('Failed to enqueue item', ['path' => $queuePath]);
        }

        return $queueItem;
    }
}
