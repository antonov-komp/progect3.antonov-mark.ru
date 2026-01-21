<?php

class UserGreetingService
{
    private AccessContextService $accessContextService;
    private BitrixUserProfileService $profileService;
    private GreetingComposerService $composerService;
    private AppLogger $logger;

    public function __construct(
        AccessContextService $accessContextService,
        BitrixUserProfileService $profileService,
        GreetingComposerService $composerService,
        AppLogger $logger
    ) {
        $this->accessContextService = $accessContextService;
        $this->profileService = $profileService;
        $this->composerService = $composerService;
        $this->logger = $logger;
    }

    public function getGreeting(array $accessData = []): string
    {
        $uiState = $this->buildUiState($accessData);

        return $uiState['full_message'];
    }

    public function renderGreeting(string $greeting): void
    {
        $safeGreeting = htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8');
        echo '<div class="app-greeting">' . $safeGreeting . '</div>';
    }

    public function getUiState(array $accessData = []): array
    {
        return $this->buildUiState($accessData);
    }

    public function getAccessContext(): array
    {
        return $this->accessContextService->getAccessContext();
    }

    private function buildUiState(array $accessData = []): array
    {
        $accessInfo = $this->accessContextService->resolveAccessContext($accessData);
        $isEmbedded = $accessInfo['is_embedded'];
        $accessContext = $accessInfo['context'];

        $authContext = $this->accessContextService->getAuthContext();
        $authSource = $authContext['auth_id'] !== '' ? 'request_token' : 'app_token';
        $authDomain = $authContext['domain'];

        $profile = $this->profileService->fetchProfile();
        $status = $profile['status'];
        $message = $profile['message'];
        $user = $profile['user'];

        $greeting = $this->composerService->buildGreeting($user['name'], $user['last_name'], $status);
        $contextMessage = $this->composerService->buildContextMessage($user['is_admin'], $isEmbedded, $user['department']);
        $fullMessage = $greeting;
        if ($contextMessage !== '') {
            $fullMessage .= ' ' . $contextMessage;
        }

        $accessMode = isset($accessData['mode']) ? (string) $accessData['mode'] : 'unknown';
        $accessDecision = isset($accessData['decision']) ? (string) $accessData['decision'] : 'allow';
        $accessReason = isset($accessData['reason']) ? (string) $accessData['reason'] : 'allow';

        $this->logOpen(
            $user['id'],
            $user['name'],
            $user['last_name'],
            $status,
            $message,
            $user['is_admin'],
            $user['admin_source'],
            $isEmbedded,
            $user['department'],
            $accessContext,
            $accessMode,
            $accessDecision,
            $accessReason,
            $authSource,
            $authDomain
        );

        return [
            'greeting' => $greeting,
            'context_message' => $contextMessage,
            'full_message' => $fullMessage,
            'status' => $status,
            'error_message' => $status === 'error' ? $message : '',
            'auth' => [
                'source' => $authSource,
            ],
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'last_name' => $user['last_name'],
                'is_admin' => $user['is_admin'],
                'admin_source' => $user['admin_source'],
                'department' => $user['department'],
            ],
            'access' => [
                'context' => $accessContext,
                'is_embedded' => $isEmbedded,
                'mode' => $accessMode,
                'decision' => $accessDecision,
                'reason' => $accessReason,
            ],
        ];
    }

    private function logOpen(
        string $userId,
        string $name,
        string $lastName,
        string $status,
        string $message,
        ?bool $isAdmin,
        string $adminSource,
        bool $isEmbedded,
        string $departmentName,
        string $accessContext,
        string $accessMode,
        string $accessDecision,
        string $accessReason,
        string $authSource,
        string $authDomain
    ): void {
        $portalId = $this->getPortalId();
        $adminLabel = $isAdmin === null ? 'unknown' : ($isAdmin ? 'yes' : 'no');
        $accessLabel = $accessContext !== '' ? $accessContext : ($isEmbedded ? 'embedded' : 'direct');
        $this->logger->log('app-open', [
            'user_id' => $userId,
            'name' => $name,
            'last_name' => $lastName,
            'portal_id' => $portalId,
            'is_admin' => $adminLabel,
            'admin_source' => $adminSource,
            'access' => $accessLabel,
            'department' => $departmentName,
            'status' => $status,
            'message' => $message,
            'access_mode' => $accessMode,
            'access_context' => $accessLabel,
            'access_decision' => $accessDecision,
            'access_reason' => $accessReason,
            'auth_source' => $authSource,
            'auth_domain' => $authDomain,
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

    private function isEmbeddedRequest(): bool
    {
        if (!empty($_REQUEST['PLACEMENT'])) {
            return true;
        }

        if (!empty($_REQUEST['PLACEMENT_OPTIONS'])) {
            return true;
        }

        if (!empty($_REQUEST['IFRAME'])) {
            return true;
        }

        if (!empty($_REQUEST['B24_FRAME'])) {
            return true;
        }

        if (!empty($_SESSION['PLACEMENT'])) {
            return true;
        }

        if (!empty($_SESSION['PLACEMENT_OPTIONS'])) {
            return true;
        }

        if (!empty($_SESSION['IFRAME'])) {
            return true;
        }

        if (!empty($_SESSION['B24_FRAME'])) {
            return true;
        }

        return false;
    }

    private function resolveAccessContext(array $accessData): array
    {
        $context = isset($accessData['context']) ? (string) $accessData['context'] : '';
        $isEmbedded = $accessData['is_embedded'] ?? null;

        if ($context === '' || !is_bool($isEmbedded)) {
            return $this->getAccessContext();
        }

        return [
            'context' => $context,
            'is_embedded' => $isEmbedded,
        ];
    }

    private function isAccessContextUnknown(): bool
    {
        if ($this->isEmbeddedRequest()) {
            return false;
        }

        if (!empty($_REQUEST)) {
            return false;
        }

        if (!empty($_SESSION['PLACEMENT']) || !empty($_SESSION['IFRAME']) || !empty($_SESSION['B24_FRAME'])) {
            return false;
        }

        return true;
    }

}
