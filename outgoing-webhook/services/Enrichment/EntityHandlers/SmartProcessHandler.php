<?php
declare(strict_types=1);

class SmartProcessHandler extends DefaultEntityHandler
{
    private DictCacheService $dicts;

    public function __construct(DictCacheService $dicts)
    {
        parent::__construct('smart_process');
        $this->dicts = $dicts;
    }

    public function buildParams(string $method, ?string $entityId, array $raw): array
    {
        if ($method !== 'crm.item.get') {
            return parent::buildParams($method, $entityId, $raw);
        }

        $entityTypeId = $this->extractEntityTypeId($raw);
        if ($entityTypeId === null || $entityId === null) {
            throw new RuntimeException('missing_entity_type_id');
        }

        return ['entityTypeId' => $entityTypeId, 'id' => $entityId];
    }

    public function getDicts(array $raw): array
    {
        $entityTypeId = $this->extractEntityTypeId($raw);
        if ($entityTypeId === null) {
            return [];
        }

        $dictTtl = 86400;

        return [
            'types' => $this->dicts->get(
                'crm_types',
                'crm.type.list',
                [],
                $dictTtl
            ),
            'categories' => $this->dicts->get(
                'crm_item_categories_' . $entityTypeId,
                'crm.category.list',
                ['entityTypeId' => $entityTypeId],
                $dictTtl
            ),
        ];
    }

    private function extractEntityTypeId(array $raw): ?string
    {
        $payload = $raw['payload'] ?? [];
        $data = is_array($payload) ? ($payload['data'] ?? []) : [];
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
}
