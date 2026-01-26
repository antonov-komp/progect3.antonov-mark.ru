<?php

class AccessControlService
{
    private AccessContextService $contextProvider;
    private AppLogger $logger;
    private BitrixUserProfileService $profileService;
    private AccessConfigService $configService;

    public function __construct(
        AccessContextService $contextProvider,
        AppLogger $logger,
        BitrixUserProfileService $profileService,
        AccessConfigService $configService
    ) {
        $this->contextProvider = $contextProvider;
        $this->logger = $logger;
        $this->profileService = $profileService;
        $this->configService = $configService;
    }

    public function evaluateAccess(): array
    {
        $contextInfo = $this->contextProvider->getAccessContext();
        $accessContext = $contextInfo['context'];
        $isEmbedded = $contextInfo['is_embedded'];

        $configResult = $this->configService->getConfig();
        $config = $configResult['config'];
        $configError = $configResult['config_error'];

        $profile = $this->profileService->fetchProfile();
        $user = $profile['user'] ?? [];
        $userId = isset($user['id']) ? (string) $user['id'] : '';
        $userName = isset($user['name']) ? (string) $user['name'] : '';
        $userLastName = isset($user['last_name']) ? (string) $user['last_name'] : '';
        $departmentIds = isset($user['department_ids']) && is_array($user['department_ids']) ? $user['department_ids'] : [];
        $superAdminId = (string) ($config['super_admin_id'] ?? '');
        $superAdminProfile = $this->profileService->fetchUserById($superAdminId);
        $denyDirect = $config['deny_direct'] ?? false;
        $isDirectContext = $accessContext === 'direct' || $accessContext === 'unknown';

        $isSuperAdmin = $userId !== '' && $superAdminId !== '' && $userId === $superAdminId;

        $decision = 'deny';
        $reason = 'unknown';

        if ($configError) {
            $decision = 'deny';
            $reason = 'config_error';
        } elseif ($config['global_enabled'] === false) {
            // Глобальное отключение - супер-админ может обойти
            if ($isSuperAdmin) {
                $decision = 'allow';
                $reason = 'super_admin';
            } else {
                $decision = 'deny';
                $reason = 'global_disabled';
            }
        } elseif ($isDirectContext && $denyDirect === true) {
            // Запрет прямого доступа - применяется ко всем, включая супер-админа
            $decision = 'deny';
            $reason = 'deny_direct';
        } elseif ($isDirectContext && $denyDirect === false) {
            // Прямой доступ разрешён
            $decision = 'allow';
            $reason = 'direct_allowed';
        } elseif ($isSuperAdmin) {
            // Супер-админ (только для embedded контекста)
            $decision = 'allow';
            $reason = 'super_admin';
        } elseif ($this->isAllowedUser($userId, $config['allowed_users']) || $this->isAllowedDepartment($departmentIds, $config['allowed_departments'])) {
            $decision = 'allow';
            $reason = 'allowed_list';
        } else {
            $decision = 'deny';
            $reason = 'not_allowed';
        }

        $isAllowed = $decision === 'allow';
        $denyMessage = $isAllowed ? '' : $this->resolveDenyMessage($reason, $superAdminId);

        if (!$isAllowed) {
            $this->logAccessDecision(
                $accessContext,
                $isEmbedded,
                $decision,
                $reason,
                $denyMessage,
                $userId,
                $userName,
                $userLastName,
                $user['department'] ?? ''
            );
        }

        return [
            'allowed' => $isAllowed,
            'message' => $denyMessage,
            'decision' => $decision,
            'reason' => $reason,
            'context' => $accessContext,
            'is_embedded' => $isEmbedded,
            'is_super_admin' => $isSuperAdmin,
            'super_admin' => $superAdminProfile,
        ];
    }

    private function logAccessDecision(
        string $accessContext,
        bool $isEmbedded,
        string $decision,
        string $reason,
        string $message,
        string $userId,
        string $name,
        string $lastName,
        string $department
    ): void {
        $portalId = $this->getPortalId();
        $accessLabel = $accessContext !== '' ? $accessContext : ($isEmbedded ? 'embedded' : 'direct');
        $maskedUserId = $this->maskId($userId);
        $maskedName = $this->maskName($name);
        $maskedLastName = $this->maskName($lastName);
        $this->logger->log('app-open', [
            'user_id' => $maskedUserId,
            'name' => $maskedName,
            'last_name' => $maskedLastName,
            'portal_id' => $portalId,
            'is_admin' => 'unknown',
            'admin_source' => 'access_control',
            'access' => $accessLabel,
            'department' => $department,
            'status' => 'deny',
            'message' => $message,
            'access_context' => $accessLabel,
            'access_decision' => $decision,
            'access_reason' => $reason,
        ]);
    }

    private function getPortalId(): string
    {
        if (!empty($_REQUEST['member_id'])) {
            return (string) $_REQUEST['member_id'];
        }

        if (!empty($_REQUEST['DOMAIN'])) {
            return (string) $_REQUEST['DOMAIN'];
        }

        return '';
    }

    private function isAllowedUser(string $userId, array $allowedUsers): bool
    {
        if ($userId === '' || empty($allowedUsers)) {
            return false;
        }

        return in_array($userId, $allowedUsers, true);
    }

    private function isAllowedDepartment(array $departmentIds, array $allowedDepartments): bool
    {
        if (empty($departmentIds) || empty($allowedDepartments)) {
            return false;
        }

        foreach ($departmentIds as $departmentId) {
            if (in_array($departmentId, $allowedDepartments, true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveDenyMessage(string $reason, string $superAdminId): string
    {
        if ($reason === 'config_error') {
            return 'Ошибка конфигурации доступа.';
        }

        if ($reason === 'deny_direct') {
            return 'Прямой доступ запрещен.';
        }

        if ($reason === 'not_allowed') {
            return 'Нет прав на доступ к приложению.';
        }

        if ($reason === 'global_disabled') {
            if ($superAdminId === '') {
                return 'Доступ к приложению временно закрыт.';
            }

            $superAdmin = $this->profileService->fetchUserById($superAdminId);
            $fullName = trim($superAdmin['full_name'] ?? '');
            if ($fullName === '') {
                $fullName = 'ID ' . $superAdminId;
            }

            return 'Супер Админ ' . $fullName . ' закрыл доступ в приложение.';
        }

        return 'Доступ ограничен.';
    }

    private function maskId(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 2) . substr($value, -2);
    }

    private function maskName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $first = function_exists('mb_substr') ? mb_substr($value, 0, 1) : substr($value, 0, 1);
        if ($first === false || $first === '') {
            return '***';
        }

        return $first . '***';
    }
}
