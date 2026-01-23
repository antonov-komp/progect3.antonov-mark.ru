<?php
declare(strict_types=1);

class LeadHandler extends DefaultEntityHandler
{
    private DictCacheService $dicts;

    public function __construct(DictCacheService $dicts)
    {
        parent::__construct('lead');
        $this->dicts = $dicts;
    }

    public function getDicts(array $raw): array
    {
        $dictTtl = 86400;

        return [
            'stages' => $this->dicts->get(
                'lead_stages',
                'crm.status.list',
                ['filter' => ['ENTITY_ID' => 'STATUS']],
                $dictTtl
            ),
        ];
    }
}
