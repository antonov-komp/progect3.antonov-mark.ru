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

        $items = $response['result'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entityTypeId = (string) ($item['entityTypeId'] ?? $item['id'] ?? '');
            $title = (string) ($item['title'] ?? $item['titleRaw'] ?? 'Смарт-процесс ' . $entityTypeId);
            if ($entityTypeId !== '') {
                $result[] = [
                    'id' => $entityTypeId,
                    'entityTypeId' => $entityTypeId,
                    'title' => $title,
                ];
            }
        }

        return $result;
    }

    /**
     * Поля смарт-процесса по entityTypeId (crm.item.userfield.list).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSmartProcessUserFields(string $entityTypeId): array
    {
        $entityTypeId = trim($entityTypeId);
        if ($entityTypeId === '') {
            return [];
        }

        $authContext = $this->accessContext->getAuthContext();
        $response = $this->bitrixClient->call('crm.item.userfield.list', [
            'entityTypeId' => $entityTypeId,
        ], $authContext);

        if ($response['error'] !== '') {
            $this->logger->log('user-fields', [
                'status' => 'error',
                'message' => 'crm.item.userfield.list failed',
                'entityTypeId' => $entityTypeId,
                'error' => $response['error'],
                'error_information' => $response['error_information'] ?? '',
            ]);

            return [];
        }

        $items = $response['result'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return $this->normalizeFields($items, 'smart');
    }

    /**
     * @param string $method Метод Bitrix24 API
     * @param string $entitySource deal|lead|contact|company
     * @return array<int, array<string, mixed>>
     */
    private function fetchUserFields(string $method, string $entitySource): array
    {
        $authContext = $this->accessContext->getAuthContext();
        $response = $this->bitrixClient->call($method, ['filter' => []], $authContext);

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
     * @param array<string, mixed> $field
     */
    private function extractTitle(array $field): string
    {
        $editLabel = $field['EDIT_FORM_LABEL'] ?? null;
        $listLabel = $field['LIST_COLUMN_LABEL'] ?? null;
        $fieldName = $field['FIELD_NAME'] ?? '';

        if (is_string($editLabel) && trim($editLabel) !== '') {
            return trim($editLabel);
        }

        if (is_array($editLabel) && isset($editLabel['ru']) && trim((string) $editLabel['ru']) !== '') {
            return trim((string) $editLabel['ru']);
        }

        if (is_string($listLabel) && trim($listLabel) !== '') {
            return trim($listLabel);
        }

        if (is_array($listLabel) && isset($listLabel['ru']) && trim((string) $listLabel['ru']) !== '') {
            return trim((string) $listLabel['ru']);
        }

        return is_string($fieldName) ? trim($fieldName) : '';
    }
}
