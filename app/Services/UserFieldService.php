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
    private UserFieldTypeService $userFieldTypeService;
    private AppLogger $logger;
    private AccessContextService $accessContext;

    /** Последнее сообщение об ошибке Bitrix24 API для передачи в ответ. */
    private string $lastErrorMessage = '';

    public function __construct(
        Bitrix24Client $bitrixClient,
        UserFieldTypeService $userFieldTypeService,
        AppLogger $logger,
        AccessContextService $accessContext
    ) {
        $this->bitrixClient = $bitrixClient;
        $this->userFieldTypeService = $userFieldTypeService;
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
                'select' => ['0' => '*', 'language' => 'ru'],
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
        $aliasMap = [
            'id' => 'ID',
            'fieldName' => 'FIELD_NAME',
            'userTypeId' => 'USER_TYPE_ID',
            'mandatory' => 'MANDATORY',
            'multiple' => 'MULTIPLE',
            'sort' => 'SORT',
            'showInList' => 'SHOW_IN_LIST',
            'editInList' => 'EDIT_IN_LIST',
            'showFilter' => 'SHOW_FILTER',
            'isSearchable' => 'IS_SEARCHABLE',
            'editFormLabel' => 'EDIT_FORM_LABEL',
            'listColumnLabel' => 'LIST_COLUMN_LABEL',
        ];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($aliasMap as $from => $to) {
                if (array_key_exists($from, $item) && !array_key_exists($to, $item)) {
                    $item[$to] = $item[$from];
                }
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
            foreach ($editLabel as $v) {
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
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
            foreach ($listLabel as $v) {
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }

        return is_string($fieldName) ? trim($fieldName) : '';
    }

    /**
     * Создание пользовательского поля для сделок.
     *
     * @see https://apidocs.bitrix24.com/api-reference/crm/deals/user-defined-fields/crm-deal-userfield-add.html
     *
     * @param array<string, mixed> $fields Поля: USER_TYPE_ID, FIELD_NAME, LABEL/EDIT_FORM_LABEL и др.
     * @return int|false ID созданного поля или false при ошибке
     */
    public function addDealUserField(array $fields): int|false
    {
        return $this->addUserField('crm.deal.userfield.add', $fields, 'deal');
    }

    /**
     * Создание пользовательского поля для лидов.
     *
     * @see https://apidocs.bitrix24.ru/rest/crm/leads/userfield/crm_lead_userfield_add.html
     *
     * @param array<string, mixed> $fields
     * @return int|false
     */
    public function addLeadUserField(array $fields): int|false
    {
        return $this->addUserField('crm.lead.userfield.add', $fields, 'lead');
    }

    /**
     * Создание пользовательского поля для контактов.
     *
     * @see https://apidocs.bitrix24.ru/rest/crm/contacts/userfield/crm_contact_userfield_add.html
     *
     * @param array<string, mixed> $fields
     * @return int|false
     */
    public function addContactUserField(array $fields): int|false
    {
        return $this->addUserField('crm.contact.userfield.add', $fields, 'contact');
    }

    /**
     * Создание пользовательского поля для компаний.
     *
     * @see https://apidocs.bitrix24.ru/rest/crm/companies/userfield/crm_company_userfield_add.html
     *
     * @param array<string, mixed> $fields
     * @return int|false
     */
    public function addCompanyUserField(array $fields): int|false
    {
        return $this->addUserField('crm.company.userfield.add', $fields, 'company');
    }

    /**
     * Создание пользовательского поля для смарт-процесса.
     *
     * Используется userfieldconfig.add (рекомендуемый метод для SPA).
     *
     * @see https://raw.githubusercontent.com/bitrix24/b24restdocs/main/tutorials/crm/how-to-add-crm-objects/how-to-add-user-field-to-spa.md
     *
     * @param string $entityId ID типа из crm.type.list (ordinal, например 23)
     * @param array<string, mixed> $fields
     * @return int|false
     */
    public function addSmartProcessUserField(string $entityId, array $fields): int|false
    {
        $this->lastErrorMessage = '';
        $entityId = trim($entityId);
        if ($entityId === '') {
            return false;
        }

        $authContext = $this->accessContext->getAuthContext();
        $entityIds = ['CRM_' . $entityId, 'DYNAMIC_' . $entityId];

        foreach ($entityIds as $listEntityId) {
            $fieldConfig = $this->prepareUserfieldconfigForSmart($fields, $listEntityId);

            $response = $this->bitrixClient->call('userfieldconfig.add', [
                'moduleId' => 'crm',
                'field' => $fieldConfig,
            ], $authContext);

            if ($response['error'] !== '') {
                $this->lastErrorMessage = trim(
                    (string) ($response['error_information'] ?? $response['error'] ?? ''),
                );
                if ($this->lastErrorMessage === '') {
                    $this->lastErrorMessage = (string) $response['error'];
                }
                $this->logger->log('user-fields', [
                    'status' => 'error',
                    'action' => 'add_smart_userfield',
                    'entity_id' => $listEntityId,
                    'error' => $response['error'],
                    'error_information' => $response['error_information'] ?? '',
                ]);
                continue;
            }

            $resultData = $response['result'] ?? [];
            $fieldId = null;
            if (is_array($resultData) && isset($resultData['field']['id'])) {
                $fieldId = (int) $resultData['field']['id'];
            } elseif (is_numeric($resultData)) {
                $fieldId = (int) $resultData;
            }

            if ($fieldId !== null && $fieldId > 0) {
                $this->logger->log('user-fields', [
                    'status' => 'ok',
                    'action' => 'add_smart_userfield',
                    'entity_id' => $listEntityId,
                    'field_id' => $fieldId,
                ]);

                return $fieldId;
            }
        }

        if ($this->lastErrorMessage === '') {
            $this->lastErrorMessage = 'Не удалось создать поле. Убедитесь, что у приложения есть scope userfieldconfig.';
        }

        return false;
    }

    /**
     * Формирование конфигурации поля для userfieldconfig.add.
     *
     * @param array<string, mixed> $fields
     * @param string $entityId CRM_23 или DYNAMIC_23
     * @return array<string, mixed>
     */
    private function prepareUserfieldconfigForSmart(array $fields, string $entityId): array
    {
        $label = trim((string) ($fields['EDIT_FORM_LABEL'] ?? $fields['LABEL'] ?? ''));
        $fieldName = trim((string) ($fields['FIELD_NAME'] ?? ''));

        $entityNum = preg_replace('/^CRM_|^DYNAMIC_/', '', $entityId);
        if ($fieldName === '') {
            $fieldName = 'UF_CRM_' . $entityNum . '_' . strtoupper(substr(uniqid(), -8));
        } else {
            $fieldName = preg_replace('/^UF_CRM_[A-Z0-9_]*/i', '', $fieldName);
            $fieldName = 'UF_CRM_' . $entityNum . '_' . (strlen($fieldName) > 0 ? strtoupper($fieldName) : strtoupper(substr(uniqid(), -8)));
        }
        $fieldName = substr($fieldName, 0, 50);

        $config = [
            'entityId' => $entityId,
            'fieldName' => $fieldName,
            'userTypeId' => (string) ($fields['USER_TYPE_ID'] ?? 'string'),
            'editFormLabel' => $label !== '' ? ['ru' => $label] : ['ru' => $fieldName],
            'multiple' => ($fields['MULTIPLE'] ?? 'N') === 'Y' ? 'Y' : 'N',
            'mandatory' => ($fields['MANDATORY'] ?? 'N') === 'Y' ? 'Y' : 'N',
            'sort' => (string) max(1, (int) ($fields['SORT'] ?? 100)),
            'showInList' => ($fields['SHOW_IN_LIST'] ?? 'N') === 'Y' ? 'Y' : 'N',
            'showFilter' => ($fields['SHOW_FILTER'] ?? 'N') === 'Y' ? 'Y' : 'N',
            'editInList' => ($fields['EDIT_IN_LIST'] ?? 'Y') === 'Y' ? 'Y' : 'N',
            'isSearchable' => ($fields['IS_SEARCHABLE'] ?? 'N') === 'Y' ? 'Y' : 'N',
        ];

        return $config;
    }

    /**
     * Создание поля-встройки (кастомный userfieldtype с handler iframe).
     *
     * 1. Регистрирует тип через userfieldtype.add (или update при дублировании)
     * 2. Создаёт поле в CRM через crm.*.userfield.add
     *
     * @param string   $section     deal|lead|contact|company|smart
     * @param array<string, string> $params handler_url, user_type_id, label, field_name?, description?, entity_type_id?, type_id?
     * @return int|false ID созданного поля или false
     */
    public function addEmbedField(string $section, array $params): int|false
    {
        $this->lastErrorMessage = '';

        if (!in_array($section, ['deal', 'lead', 'contact', 'company', 'smart'], true)) {
            $this->lastErrorMessage = 'Раздел должен быть deal, lead, contact, company или smart.';
            return false;
        }
        if ($section === 'smart') {
            $spaId = trim((string) ($params['type_id'] ?? $params['entity_type_id'] ?? ''));
            if ($spaId === '') {
                $this->lastErrorMessage = 'Для смарт-процесса укажите entityTypeId или typeId.';
                return false;
            }
        }

        $handlerUrl = trim((string) ($params['handler_url'] ?? ''));
        $userTypeId = trim((string) ($params['user_type_id'] ?? ''));
        $label = trim((string) ($params['label'] ?? ''));
        $fieldName = trim((string) ($params['field_name'] ?? ''));
        $description = trim((string) ($params['description'] ?? ''));

        if ($handlerUrl === '') {
            $this->lastErrorMessage = 'Укажите URL handler\'а.';
            return false;
        }
        if ($userTypeId === '') {
            $this->lastErrorMessage = 'Укажите код типа поля (user_type_id).';
            return false;
        }
        if ($label === '') {
            $this->lastErrorMessage = 'Укажите название поля.';
            return false;
        }

        $ok = $this->userFieldTypeService->registerUserFieldType(
            $userTypeId,
            $handlerUrl,
            $label,
            $description,
        );

        if (!$ok) {
            $this->lastErrorMessage = $this->userFieldTypeService->getLastError();
            if ($this->lastErrorMessage === '') {
                $this->lastErrorMessage = 'Не удалось зарегистрировать тип поля.';
            }
            return false;
        }

        $fields = [
            'USER_TYPE_ID' => $userTypeId,
            'EDIT_FORM_LABEL' => $label,
            'MANDATORY' => 'N',
            'MULTIPLE' => 'N',
            'SHOW_IN_LIST' => 'N',
            'SHOW_FILTER' => 'N',
            'SORT' => 100,
        ];
        if ($fieldName !== '') {
            $fields['FIELD_NAME'] = $fieldName;
        }

        $fieldId = false;
        switch ($section) {
            case 'deal':
                $fieldId = $this->addDealUserField($fields);
                break;
            case 'lead':
                $fieldId = $this->addLeadUserField($fields);
                break;
            case 'contact':
                $fieldId = $this->addContactUserField($fields);
                break;
            case 'company':
                $fieldId = $this->addCompanyUserField($fields);
                break;
            case 'smart':
                $spaId = trim((string) ($params['type_id'] ?? $params['entity_type_id'] ?? ''));
                $fieldId = $this->addSmartProcessUserField($spaId, $fields);
                break;
        }

        if ($fieldId === false) {
            $apiErr = $this->getLastError();
            $this->lastErrorMessage = $apiErr !== '' ? $apiErr : 'Не удалось создать поле в CRM.';
        }

        return $fieldId;
    }

    /**
     * Возвращает последнее сообщение об ошибке Bitrix24 API.
     */
    public function getLastError(): string
    {
        return $this->lastErrorMessage;
    }

    /**
     * Общий метод добавления пользовательского поля через entity-specific API.
     *
     * @param string $method crm.deal.userfield.add, crm.lead.userfield.add и т.д.
     * @param array<string, mixed> $fields
     * @param string $entitySource deal|lead|contact|company
     * @return int|false
     */
    private function addUserField(string $method, array $fields, string $entitySource): int|false
    {
        $this->lastErrorMessage = '';
        $authContext = $this->accessContext->getAuthContext();
        $prepared = $this->prepareFieldsForAdd($fields, $entitySource);

        $response = $this->bitrixClient->call($method, [
            'fields' => $prepared,
        ], $authContext);

        if ($response['error'] !== '') {
            $this->lastErrorMessage = trim(
                (string) ($response['error_information'] ?? $response['error'] ?? ''),
            );
            if ($this->lastErrorMessage === '') {
                $this->lastErrorMessage = (string) $response['error'];
            }
            $this->logger->log('user-fields', [
                'status' => 'error',
                'action' => 'add_userfield',
                'method' => $method,
                'entity_source' => $entitySource,
                'error' => $response['error'],
                'error_information' => $response['error_information'] ?? '',
            ]);

            return false;
        }

        $result = $response['result'];
        if (!is_numeric($result)) {
            return false;
        }

        $fieldId = (int) $result;
        $this->logger->log('user-fields', [
            'status' => 'ok',
            'action' => 'add_userfield',
            'method' => $method,
            'entity_source' => $entitySource,
            'field_id' => $fieldId,
        ]);

        return $fieldId;
    }

    /**
     * Подготовка полей для отправки в API.
     * Нормализация FIELD_NAME (префикс UF_CRM_*), LABEL/EDIT_FORM_LABEL, значений по умолчанию.
     *
     * @param array<string, mixed> $fields
     * @param string $entitySource deal|lead|contact|company|smart
     * @return array<string, mixed>
     */
    private function prepareFieldsForAdd(array $fields, string $entitySource): array
    {
        $result = $fields;

        // LABEL или EDIT_FORM_LABEL — одно из них обязательно для label
        $label = trim((string) ($fields['EDIT_FORM_LABEL'] ?? $fields['LABEL'] ?? ''));
        if ($label !== '') {
            $result['LABEL'] = $label;
            if (!isset($result['EDIT_FORM_LABEL'])) {
                $result['EDIT_FORM_LABEL'] = $label;
            }
        }

        // FIELD_NAME — без префикса UF_CRM_, Bitrix добавляет автоматически; макс. 20 символов без префикса
        $fieldName = trim((string) ($fields['FIELD_NAME'] ?? ''));
        if ($fieldName === '') {
            $prefix = match ($entitySource) {
                'deal' => 'UF_CRM_DEAL_',
                'lead' => 'UF_CRM_LEAD_',
                'contact' => 'UF_CRM_CONTACT_',
                'company' => 'UF_CRM_COMPANY_',
                default => 'UF_CRM_',
            };
            // Ограничение: 20 символов всего, UF_CRM_* занимает ~13 — суффикс до 7 символов
            $result['FIELD_NAME'] = substr((string) time(), -7);
        } else {
            // Убрать префикс UF_CRM_ если передан — API примет без него
            $cleaned = preg_replace('/^UF_CRM_[A-Z_]*/i', '', $fieldName);
            if ($cleaned !== '') {
                $result['FIELD_NAME'] = $cleaned;
            }
        }

        // Значения по умолчанию для опциональных параметров
        $defaults = [
            'MANDATORY' => 'N',
            'MULTIPLE' => 'N',
            'SHOW_IN_LIST' => 'N',
            'SHOW_FILTER' => 'N',
            'SORT' => 100,
        ];
        foreach ($defaults as $key => $def) {
            if (!isset($result[$key]) || $result[$key] === '') {
                $result[$key] = $def;
            }
        }

        // Преобразование boolean-подобных значений
        foreach (['MANDATORY', 'MULTIPLE', 'SHOW_IN_LIST', 'SHOW_FILTER', 'EDIT_IN_LIST', 'IS_SEARCHABLE'] as $key) {
            if (isset($result[$key])) {
                $v = $result[$key];
                $result[$key] = ($v === true || $v === 'Y' || $v === '1' || $v === 1) ? 'Y' : 'N';
            }
        }

        if (isset($result['SORT'])) {
            $result['SORT'] = max(1, (int) $result['SORT']);
        }

        return $result;
    }
}
