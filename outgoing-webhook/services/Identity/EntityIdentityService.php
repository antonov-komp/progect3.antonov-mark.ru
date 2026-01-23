<?php
declare(strict_types=1);

class EntityIdentityService
{
    private RequestService $request;

    public function __construct(RequestService $request)
    {
        $this->request = $request;
    }

    public function extractEntityId(array $payload): ?string
    {
        $data = $payload['data'] ?? [];
        if (is_array($data)) {
            if (isset($data['FIELDS_AFTER']['TASK_ID'])) {
                return (string) $data['FIELDS_AFTER']['TASK_ID'];
            }
            if (isset($data['FIELDS']['TASK_ID'])) {
                return (string) $data['FIELDS']['TASK_ID'];
            }
            if (isset($data['TASK_ID'])) {
                return (string) $data['TASK_ID'];
            }
            if (isset($data['FIELDS']['ID'])) {
                return (string) $data['FIELDS']['ID'];
            }
            if (isset($data['FIELDS_AFTER']['ID'])) {
                return (string) $data['FIELDS_AFTER']['ID'];
            }
            if (isset($data['FIELDS_BEFORE']['ID'])) {
                return (string) $data['FIELDS_BEFORE']['ID'];
            }
            if (isset($data['ID'])) {
                return (string) $data['ID'];
            }
        }

        return null;
    }

    public function extractCommentId(array $payload): ?string
    {
        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        $paths = [
            ['FIELDS_AFTER', 'MESSAGE_ID'],
            ['FIELDS', 'MESSAGE_ID'],
            ['FIELDS_AFTER', 'ID'],
            ['FIELDS', 'ID'],
            ['MESSAGE_ID'],
            ['ID'],
        ];

        foreach ($paths as $path) {
            $value = $this->request->getFirstValue($data, [$path]);
            if ($value !== null) {
                return (string) $value;
            }
        }

        return null;
    }

    public function normalizeEntityId(?string $entityId): ?string
    {
        if ($entityId === null) {
            return null;
        }

        $value = trim((string) $entityId);
        if ($value === '' || $value === '0') {
            return null;
        }

        return $value;
    }

    public function extractTaskId(array $payload): ?string
    {
        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        $value = $this->request->getFirstValue($data, [
            ['FIELDS_AFTER', 'TASK_ID'],
            ['FIELDS', 'TASK_ID'],
            'TASK_ID',
        ]);

        return $value !== null ? (string) $value : null;
    }

    public function extractMessageId(array $payload): ?string
    {
        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        $value = $this->request->getFirstValue($data, [
            ['FIELDS_AFTER', 'MESSAGE_ID'],
            ['FIELDS', 'MESSAGE_ID'],
            'MESSAGE_ID',
        ]);

        return $value !== null ? (string) $value : null;
    }

    public function resolveEntityType(string $eventType): string
    {
        if (str_starts_with($eventType, 'ONCRMDEAL')) {
            return 'deal';
        }
        if (str_starts_with($eventType, 'ONCRMLEAD')) {
            return 'lead';
        }
        if (str_starts_with($eventType, 'ONCRMCONTACT')) {
            return 'contact';
        }
        if (str_starts_with($eventType, 'ONCRMCOMPANY')) {
            return 'company';
        }
        if (str_starts_with($eventType, 'ONCRMITEM')) {
            return 'smart_process';
        }
        if (str_starts_with($eventType, 'ONTASK')) {
            return 'task';
        }
        if (str_starts_with($eventType, 'ONUSER')) {
            return 'user';
        }
        if (str_starts_with($eventType, 'SONET_GROUP')) {
            return 'project';
        }
        if (str_starts_with($eventType, 'ONCRMUSERFIELD')) {
            return 'crm_userfield';
        }

        return 'unknown';
    }
}
