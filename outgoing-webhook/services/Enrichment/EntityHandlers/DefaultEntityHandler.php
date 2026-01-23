<?php
declare(strict_types=1);

class DefaultEntityHandler implements EntityHandlerInterface
{
    private string $entityType;

    public function __construct(string $entityType)
    {
        $this->entityType = $entityType;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function buildParams(string $method, ?string $entityId, array $raw): array
    {
        if ($method === 'crm.userfield.list') {
            return [];
        }

        if ($entityId === null) {
            throw new RuntimeException('missing_entity_id');
        }

        return ['id' => $entityId];
    }

    public function getDicts(array $raw): array
    {
        return [];
    }
}
