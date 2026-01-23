<?php
declare(strict_types=1);

class DealHandler extends DefaultEntityHandler
{
    private DictCacheService $dicts;

    public function __construct(DictCacheService $dicts)
    {
        parent::__construct('deal');
        $this->dicts = $dicts;
    }

    public function getDicts(array $raw): array
    {
        $dictTtl = 86400;

        return [
            'categories' => $this->dicts->get(
                'deal_categories',
                'crm.category.list',
                ['entityTypeId' => 2],
                $dictTtl
            ),
            'stages' => $this->dicts->get(
                'deal_stages',
                'crm.status.list',
                ['filter' => ['ENTITY_ID' => 'DEAL_STAGE']],
                $dictTtl
            ),
        ];
    }
}
