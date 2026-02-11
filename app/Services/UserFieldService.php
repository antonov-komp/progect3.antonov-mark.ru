<?php

/**
 * Сервис работы с пользовательскими полями Bitrix24 CRM.
 *
 * Методы Bitrix24 REST API:
 * - crm.deal.userfield.list
 * - crm.lead.userfield.list
 * - crm.contact.userfield.list
 * - crm.company.userfield.list
 * - crm.type.list
 * - crm.item.userfield.list
 *
 * @see https://apidocs.bitrix24.ru/api-reference/crm/deals/user-defined-fields/crm-deal-userfield-list.html
 * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/user-defined-fields/
 */
class UserFieldService
{
    private Bitrix24Client $bitrixClient;
    private AppLogger $logger;
    private AccessContextService $accessContext;

    public function __construct(
        Bitrix24Client $bitrixClient,
        AppLogger $logger,
        AccessContextService $accessContext
    ) {
        $this->bitrixClient = $bitrixClient;
        $this->logger = $logger;
        $this->accessContext = $accessContext;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDealUserFields(): array
    {
        return $this->fetchUserFields('crm.deal.userfield.list', 'deal');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLeadUserFields(): array
    {
        return $this->fetchUserFields('crm.lead.userfield.list', 'lead');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getContactUserFields(): array
    {
        return $this->fetchUserFields('crm.contact.userfield.list', 'contact');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCompanyUserFields(): array
    {
        return $this->fetchUserFields('crm.company.userfield.list', 'company');
    }

    /**
     * Список типов смарт-процессов (crm.type.list).
     *
     * @return array<int, array{id:string, entityTypeId:string, title:string}>
     */
    public function getSmartProcessTypes(): array
    {
        $authContext = $this->accessContext->getAuthContext();
        $response = $this->bitrixClient->call('crm.type.list', ['filter' => []], $authContext);

        if ($response['error'] !== '') {
            $this->logger->log('user-fields', [
                'status' => 'error',
                'message' => 'crm.type.list failed',
                'error' => $response['error'],
                'error_information' => $response['error_information'] ?? '',
            ]);

            return [];
        }

        $resultData = $response['result'] ?? [];
        $items = is_array($resultData) && isset($resultData['types']) && is_array($resultData['types'])
            ? $resultData['types']
            : (is_array($resultData) ? $resultData : []);

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $typeId = (string) ($item['id'] ?? '');
            $entityTypeId = (string) ($item['entityTypeId'] ?? $typeId);
            $title = (string) ($item['title'] ?? $item['titleRaw'] ?? 'Смарт-процесс ' . $entityTypeId);
            if ($entityTypeId !== '' || $typeId !== '') {
                $result[] = [
                    'id' => $typeId !== '' ? $typeId : $entityTypeId,
                    'entityTypeId' => $entityTypeId,
                    'title' => $title,
                ];
            }
        }

        return $result;
    }

    /**
     * Поля смарт-процесса. entityId — id из crm.type.list для ENTITY_ID=CRM_{id}.
     *
     * 1. userfieldconfig.list (scope userfieldconfig) — рекомендованный метод
     * 2. crm.userfield.list — запасной вариант
     *
     * @see https://apidocs.bitrix24.com/api-reference/crm/universal/userfieldconfig/userfieldconfig/userfieldconfig-list.html
     * @see https://apidocs.bitrix24.com/api-reference/crm/universal/userfieldconfig/entity-id.html
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSmartProcessUserFields(string $entityId): array
    {
        $entityId = trim($entityId);
        if ($entityId === '') {
            return [];
        }

        $authContext = $this->accessContext->getAuthContext();

        $entityIds = [
            'CRM_' . $entityId,
            'DYNAMIC_' . $entityId,
        ];

        foreach ($entityIds as $listEntityId) {
            $response = $this->bitrixClient->call('userfieldconfig.list', [
                'moduleId' => 'crm',
                'filter' => ['entityId' => $listEntityId],
            ], $authContext);

            if ($response['error'] !== '') {
                continue;
            }

            $resultData = $response['result'] ?? [];
            $items = $this->extractUserFieldItems($resultData);
            if ($items !== []) {
                return $this->normalizeFields($items, 'smart');
            }
        }

        foreach ($entityIds as $listEntityId) {
            $response = $this->bitrixClient->call('crm.userfield.list', [
                'filter' => ['ENTITY_ID' => $listEntityId, 'LANG' => 'ru'],
            ], $authContext);

            if ($response['error'] !== '') {
                continue;
            }

            $resultData = $response['result'] ?? [];
            $items = $this->extractUserFieldItems($resultData);
            if ($items !== []) {
                return $this->normalizeFields($items, 'smart');
            }
        }

        $this->logger->log('user-fields', [
            'status' => 'warning',
            'message' => 'No user fields found for smart process',
            'entityId' => $entityId,
            'triedEntityIds' => $entityIds,
        ]);

        return [];
    }

    /**
     * @param string $method Метод Bitrix24 API
     * @param string $entitySource deal|lead|contact|company
     * @return array<int, array<string, mixed>>
     */
    private function fetchUserFields(string $method, string $entitySource): array
    {
        $authContext = $this->accessContext->getAuthContext();
        $response = $this->bitrixClient->call($method, [
            'filter' => ['LANG' => 'ru'],
        ], $authContext);

        if ($response['error'] !== '') {
            $this->logger->log('user-fields', [
                'status' => 'error',
                'message' => $method . ' failed',
                'entity_source' => $entitySource,
                'error' => $response['error'],
                'error_information' => $response['error_information'] ?? '',
            ]);

            return [];
        }

        $items = $response['result'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return $this->normalizeFields($items, $entitySource);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param string $entitySource
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFields(array $items, string $entitySource): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item['entity_source'] = $entitySource;
            $item['TITLE'] = $this->extractTitle($item);
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Извлечение массива полей из ответа API (поддержка разных форматов).
     * crm.userfield.list может вернуть ассоциативный массив [fieldName => config].
     *
     * @param mixed $resultData
     * @return array<int, array<string, mixed>>
     */
    private function extractUserFieldItems($resultData): array
    {
        if (is_array($resultData) && isset($resultData['fields']) && is_array($resultData['fields'])) {
            return $resultData['fields'];
        }
        if (is_array($resultData) && isset($resultData['userfields']) && is_array($resultData['userfields'])) {
            return $resultData['userfields'];
        }
        if (is_array($resultData) && isset($resultData['items']) && is_array($resultData['items'])) {
            return $resultData['items'];
        }
        if (is_array($resultData) && isset($resultData[0]) && !isset($resultData['types'])) {
            return $resultData;
        }
        if (is_array($resultData) && $resultData !== []) {
            $firstKey = array_key_first($resultData);
            if (is_string($firstKey) && str_starts_with($firstKey, 'UF_')) {
                return array_values($resultData);
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function extractTitle(array $field): string
    {
        $editLabel = $field['EDIT_FORM_LABEL'] ?? $field['editFormLabel'] ?? null;
        $listLabel = $field['LIST_COLUMN_LABEL'] ?? $field['listColumnLabel'] ?? null;
        $fieldName = $field['FIELD_NAME'] ?? $field['fieldName'] ?? '';

        if (is_string($editLabel) && trim($editLabel) !== '') {
            return trim($editLabel);
        }

        if (is_array($editLabel)) {
            foreach (['ru', 'en'] as $lang) {
                if (isset($editLabel[$lang]) && is_string($editLabel[$lang]) && trim($editLabel[$lang]) !== '') {
                    return trim($editLabel[$lang]);
                }
            }
        }

        if (is_string($listLabel) && trim($listLabel) !== '') {
            return trim($listLabel);
        }

        if (is_array($listLabel)) {
            foreach (['ru', 'en'] as $lang) {
                if (isset($listLabel[$lang]) && is_string($listLabel[$lang]) && trim($listLabel[$lang]) !== '') {
                    return trim($listLabel[$lang]);
                }
            }
        }

        return is_string($fieldName) ? trim($fieldName) : '';
    }
}
