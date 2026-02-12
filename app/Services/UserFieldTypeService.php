<?php

/**
 * Сервис регистрации кастомных типов пользовательских полей (userfieldtype).
 *
 * Методы Bitrix24 REST API:
 * - userfieldtype.add — регистрация типа
 * - userfieldtype.update — обновление типа (если уже существует)
 *
 * @see https://apidocs.bitrix24.com/api-reference/widgets/user-field/userfieldtype-add.html
 * @see https://apidocs.bitrix24.com/api-reference/widgets/user-field/userfieldtype-update.html
 */
class UserFieldTypeService
{
    private Bitrix24Client $bitrixClient;
    private AppLogger $logger;
    private AccessContextService $accessContext;

    private string $lastErrorMessage = '';

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
     * Регистрация кастомного типа поля-встройки (iframe).
     *
     * При ошибке «Handler already binded» выполняется fallback на userfieldtype.update.
     *
     * @param string $userTypeId  Код типа: a-z, 0-9, _ (макс. 50 символов)
     * @param string $handlerUrl  Полный URL страницы для iframe
     * @param string $title       Название типа (для администраторов)
     * @param string $description Описание типа (опционально)
     * @return bool true при успехе
     */
    public function registerUserFieldType(
        string $userTypeId,
        string $handlerUrl,
        string $title,
        string $description = ''
    ): bool {
        $this->lastErrorMessage = '';

        $params = [
            'USER_TYPE_ID' => $userTypeId,
            'HANDLER' => $handlerUrl,
            'TITLE' => $title,
            'DESCRIPTION' => $description,
        ];

        $authContext = $this->accessContext->getAuthContext();

        $response = $this->bitrixClient->call('userfieldtype.add', $params, $authContext);

        if ($response['error'] !== '') {
            $errorDesc = trim((string) ($response['error_information'] ?? $response['error'] ?? ''));
            if ($errorDesc === '') {
                $errorDesc = (string) $response['error'];
            }

            $isHandlerBinded = str_contains($errorDesc, 'Handler already binded')
                || str_contains($errorDesc, 'already binded');

            if ($isHandlerBinded) {
                $response = $this->bitrixClient->call('userfieldtype.update', $params, $authContext);
                if ($response['error'] !== '') {
                    $this->lastErrorMessage = trim(
                        (string) ($response['error_information'] ?? $response['error'] ?? ''),
                    );
                    if ($this->lastErrorMessage === '') {
                        $this->lastErrorMessage = (string) $response['error'];
                    }
                    $this->logger->log('user-fields', [
                        'status' => 'error',
                        'action' => 'userfieldtype_update',
                        'user_type_id' => $userTypeId,
                        'error' => $response['error'],
                        'error_information' => $response['error_information'] ?? '',
                    ]);
                    return false;
                }

                $this->logger->log('user-fields', [
                    'status' => 'ok',
                    'action' => 'userfieldtype_update',
                    'user_type_id' => $userTypeId,
                    'message' => 'Fallback to update after add failed (handler already binded)',
                ]);
                return true;
            }

            $this->lastErrorMessage = $errorDesc;
            $this->logger->log('user-fields', [
                'status' => 'error',
                'action' => 'userfieldtype_add',
                'user_type_id' => $userTypeId,
                'error' => $response['error'],
                'error_information' => $response['error_information'] ?? '',
            ]);
            return false;
        }

        $this->logger->log('user-fields', [
            'status' => 'ok',
            'action' => 'userfieldtype_add',
            'user_type_id' => $userTypeId,
        ]);

        return true;
    }

    /**
     * Последнее сообщение об ошибке Bitrix24 API.
     */
    public function getLastError(): string
    {
        return $this->lastErrorMessage;
    }
}
