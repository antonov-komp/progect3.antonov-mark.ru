<?php

class BitrixUserProfileService
{
    private AccessContextService $accessContextService;
    private Bitrix24Client $bitrix24Client;

    public function __construct(AccessContextService $accessContextService, Bitrix24Client $bitrix24Client)
    {
        $this->accessContextService = $accessContextService;
        $this->bitrix24Client = $bitrix24Client;
    }

    /**
     * @return array{status:string,message:string,user:array{id:string,name:string,last_name:string,is_admin:?bool,admin_source:string,department:string,department_ids:array<int, string>}}
     */
    public function fetchProfile(): array
    {
        $status = 'ok';
        $message = 'user.current ok';
        $userId = '';
        $name = '';
        $lastName = '';
        $isAdmin = null;
        $adminSource = 'unknown';
        $departmentName = '';
        $departmentIds = [];

        try {
            // Используется метод Bitrix24: user.current
            // Документация: https://context7.com/bitrix24/rest/user.current
            $result = $this->callBitrix('user.current');

            if (!empty($result['error'])) {
                $status = 'error';
                $message = $this->buildErrorMessage($result);
            }

            $user = $result['result'] ?? [];
            $userId = isset($user['ID']) ? (string) $user['ID'] : '';
            $name = isset($user['NAME']) ? (string) $user['NAME'] : '';
            $lastName = isset($user['LAST_NAME']) ? (string) $user['LAST_NAME'] : '';
            $isAdmin = $this->resolveAdminStatus($user);
            if ($isAdmin !== null) {
                $adminSource = 'user.current';
            }
            $userDetails = $this->getUserDetails($userId);
            if ($userDetails['is_admin'] !== null) {
                $isAdmin = $userDetails['is_admin'];
                $adminSource = 'user.get';
            }
            if ($isAdmin === null) {
                $isAdmin = $this->getAdminStatusViaUserAdmin();
                if ($isAdmin !== null) {
                    $adminSource = 'user.admin';
                }
            }
            $departmentIds = $userDetails['department_ids'];
            $departmentName = $this->resolveDepartmentName($departmentIds);
        } catch (Throwable $exception) {
            $status = 'error';
            $message = 'exception: ' . $exception->getMessage();
        }

        return [
            'status' => $status,
            'message' => $message,
            'user' => [
                'id' => $userId,
                'name' => $name,
                'last_name' => $lastName,
                'is_admin' => $isAdmin,
                'admin_source' => $adminSource,
                'department' => $departmentName,
                'department_ids' => $departmentIds,
            ],
        ];
    }

    /**
     * @return array{id:string,name:string,last_name:string,full_name:string}
     */
    public function fetchUserById(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [
                'id' => '',
                'name' => '',
                'last_name' => '',
                'full_name' => '',
            ];
        }

        // Используется метод Bitrix24: user.get
        // Документация: https://context7.com/bitrix24/rest/user.get
        $result = $this->callBitrix('user.get', [
            'FILTER' => ['ID' => $userId],
            'SELECT' => ['ID', 'NAME', 'LAST_NAME'],
        ]);

        if (!empty($result['error'])) {
            return [
                'id' => $userId,
                'name' => '',
                'last_name' => '',
                'full_name' => 'ID ' . $userId,
            ];
        }

        $userList = $result['result'] ?? [];
        $user = is_array($userList) && isset($userList[0]) ? $userList[0] : [];
        $name = isset($user['NAME']) ? (string) $user['NAME'] : '';
        $lastName = isset($user['LAST_NAME']) ? (string) $user['LAST_NAME'] : '';
        $fullName = trim($name . ' ' . $lastName);
        if ($fullName === '') {
            $fullName = 'ID ' . $userId;
        }

        return [
            'id' => $userId,
            'name' => $name,
            'last_name' => $lastName,
            'full_name' => $fullName,
        ];
    }

    /**
     * @return array{id:string,name:string,last_name:string,is_admin:?bool,department:string,department_ids:array<int,string>}
     */
    public function fetchUserProfileById(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [
                'id' => '',
                'name' => '',
                'last_name' => '',
                'is_admin' => null,
                'department' => '',
                'department_ids' => [],
            ];
        }

        // Используется метод Bitrix24: user.get
        // Документация: https://context7.com/bitrix24/rest/user.get
        $result = $this->callBitrix('user.get', [
            'FILTER' => ['ID' => $userId],
            'SELECT' => ['ID', 'NAME', 'LAST_NAME', 'UF_DEPARTMENT', 'IS_ADMIN', 'ADMIN'],
        ]);

        if (!empty($result['error'])) {
            return [
                'id' => $userId,
                'name' => '',
                'last_name' => '',
                'is_admin' => null,
                'department' => '',
                'department_ids' => [],
            ];
        }

        $userList = $result['result'] ?? [];
        $user = is_array($userList) && isset($userList[0]) ? $userList[0] : [];
        $name = isset($user['NAME']) ? (string) $user['NAME'] : '';
        $lastName = isset($user['LAST_NAME']) ? (string) $user['LAST_NAME'] : '';

        $departmentIds = [];
        if (!empty($user['UF_DEPARTMENT']) && is_array($user['UF_DEPARTMENT'])) {
            foreach ($user['UF_DEPARTMENT'] as $departmentId) {
                $departmentIds[] = (string) $departmentId;
            }
        }

        $departmentName = $this->resolveDepartmentName($departmentIds);

        return [
            'id' => $userId,
            'name' => $name,
            'last_name' => $lastName,
            'is_admin' => $this->resolveAdminStatus($user),
            'department' => $departmentName,
            'department_ids' => $departmentIds,
        ];
    }

    private function resolveAdminStatus(array $user): ?bool
    {
        if (isset($user['IS_ADMIN'])) {
            return $user['IS_ADMIN'] === true || $user['IS_ADMIN'] === 'Y' || $user['IS_ADMIN'] === '1';
        }

        if (isset($user['ADMIN'])) {
            return $user['ADMIN'] === true || $user['ADMIN'] === 'Y' || $user['ADMIN'] === '1';
        }

        return null;
    }

    private function getUserDetails(string $userId): array
    {
        if ($userId === '') {
            return [
                'is_admin' => null,
                'department_ids' => [],
            ];
        }

        // Используется метод Bitrix24: user.get
        // Документация: https://context7.com/bitrix24/rest/user.get
        $result = $this->callBitrix('user.get', [
            'FILTER' => ['ID' => $userId],
            'SELECT' => ['ID', 'UF_DEPARTMENT', 'IS_ADMIN', 'ADMIN'],
        ]);

        if (!empty($result['error'])) {
            return [
                'is_admin' => null,
                'department_ids' => [],
            ];
        }

        $userList = $result['result'] ?? [];
        $user = is_array($userList) && isset($userList[0]) ? $userList[0] : [];

        $departmentIds = [];
        if (!empty($user['UF_DEPARTMENT']) && is_array($user['UF_DEPARTMENT'])) {
            foreach ($user['UF_DEPARTMENT'] as $departmentId) {
                $departmentIds[] = (string) $departmentId;
            }
        }

        return [
            'is_admin' => $this->resolveAdminStatus($user),
            'department_ids' => $departmentIds,
        ];
    }

    private function resolveDepartmentName(array $departmentIds): string
    {
        if (empty($departmentIds)) {
            return '';
        }

        $departmentId = (string) $departmentIds[0];
        if ($departmentId === '') {
            return '';
        }

        // Используется метод Bitrix24: department.get
        // Документация: https://context7.com/bitrix24/rest/department.get
        $result = $this->callBitrix('department.get', [
            'ID' => $departmentId,
        ]);

        if (!empty($result['error'])) {
            return $departmentId;
        }

        $departmentList = $result['result'] ?? [];
        $department = is_array($departmentList) && isset($departmentList[0]) ? $departmentList[0] : [];
        if (!empty($department['NAME'])) {
            return (string) $department['NAME'];
        }

        return $departmentId;
    }

    private function getAdminStatusViaUserAdmin(): ?bool
    {
        // Используется метод Bitrix24: user.admin
        // Документация: https://context7.com/bitrix24/rest/user.admin
        $result = $this->callBitrix('user.admin');

        if (!empty($result['error'])) {
            return null;
        }

        if (!array_key_exists('result', $result)) {
            return null;
        }

        if ($result['result'] === true || $result['result'] === 'Y' || $result['result'] === 1 || $result['result'] === '1') {
            return true;
        }

        if ($result['result'] === false || $result['result'] === 'N' || $result['result'] === 0 || $result['result'] === '0') {
            return false;
        }

        return null;
    }

    private function buildErrorMessage(array $result): string
    {
        $error = isset($result['error']) ? (string) $result['error'] : 'unknown_error';
        $info = isset($result['error_information']) ? (string) $result['error_information'] : '';
        $message = $error;

        if ($info !== '') {
            $message .= ': ' . $info;
        }

        return $message;
    }

    private function callBitrix(string $method, array $params = []): array
    {
        $authContext = $this->accessContextService->getAuthContext();
        return $this->bitrix24Client->call($method, $params, $authContext);
    }
}
