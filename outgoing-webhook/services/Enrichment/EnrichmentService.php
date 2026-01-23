<?php
declare(strict_types=1);

class EnrichmentService
{
    private RestService $rest;
    private ErrorService $errors;
    private StateStorage $stateStorage;
    /** @var array<string, EntityHandlerInterface> */
    private array $handlers = [];

    /**
     * @param EntityHandlerInterface[] $handlers
     */
    public function __construct(RestService $rest, ErrorService $errors, StateStorage $stateStorage, array $handlers)
    {
        $this->rest = $rest;
        $this->errors = $errors;
        $this->stateStorage = $stateStorage;

        foreach ($handlers as $handler) {
            $this->handlers[$handler->getEntityType()] = $handler;
        }
    }

    public function resolveMethod(string $eventType): ?string
    {
        if (str_starts_with($eventType, 'ONCRMDEAL')) {
            return 'crm.deal.get';
        }
        if (str_starts_with($eventType, 'ONCRMLEAD')) {
            return 'crm.lead.get';
        }
        if (str_starts_with($eventType, 'ONCRMCONTACT')) {
            return 'crm.contact.get';
        }
        if (str_starts_with($eventType, 'ONCRMCOMPANY')) {
            return 'crm.company.get';
        }
        if (str_starts_with($eventType, 'ONCRMITEM')) {
            return 'crm.item.get';
        }
        if (str_starts_with($eventType, 'ONTASK')) {
            return 'tasks.task.get';
        }
        if (str_starts_with($eventType, 'ONUSER')) {
            return 'user.get';
        }
        if (str_starts_with($eventType, 'SONET_GROUP')) {
            return 'sonet_group.get';
        }
        if (str_starts_with($eventType, 'ONCRMUSERFIELD')) {
            return 'crm.userfield.list';
        }

        return null;
    }

    public function buildEnriched(
        string $eventType,
        string $entityType,
        ?string $entityId,
        array $raw,
        string $rawPath
    ): array {
        $method = $this->resolveMethod($eventType);
        if ($method === null) {
            return ['error' => 'unknown_event_type'];
        }

        $handler = $this->handlers[$entityType] ?? new DefaultEntityHandler($entityType);

        try {
            $params = $handler->buildParams($method, $entityId, $raw);
        } catch (RuntimeException $exception) {
            return ['error' => $exception->getMessage()];
        }

        $startedAt = microtime(true);
        $result = $this->rest->call($method, $params);
        $elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);

        if (!empty($result['error'])) {
            return ['error' => 'rest_error', 'details' => $result['error']];
        }

        $data = [
            $entityType => $result['result'] ?? $result,
        ];

        foreach ($handler->getDicts($raw) as $key => $value) {
            $data[$key] = $value;
        }

        return [
            'eventType' => $eventType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'enrichedAt' => outgoingWebhookNow(),
            'source' => [
                'method' => $method,
                'responseTimeMs' => $elapsedMs,
            ],
            'rawRef' => $rawPath,
            'data' => $data,
        ];
    }

    public function extractEntityTypeId(array $payload): ?string
    {
        $data = $payload['data'] ?? [];
        if (is_array($data)) {
            if (isset($data['FIELDS']['ENTITY_TYPE_ID'])) {
                return (string) $data['FIELDS']['ENTITY_TYPE_ID'];
            }
            if (isset($data['ENTITY_TYPE_ID'])) {
                return (string) $data['ENTITY_TYPE_ID'];
            }
        }

        return null;
    }

    public function detectFieldChanges(string $entityType, string $entityId, array $current, string $eventType): void
    {
        $this->stateStorage->detectFieldChanges($entityType, $entityId, $current, $eventType);
    }
}
