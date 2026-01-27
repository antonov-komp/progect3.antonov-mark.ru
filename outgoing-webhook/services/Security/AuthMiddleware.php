<?php
declare(strict_types=1);

/**
 * Middleware для аутентификации
 * 
 * Ответственность:
 * - Извлечение токена из payload
 * - Валидация токена
 * - Проверка IP-адреса (если настроено)
 */
class AuthMiddleware
{
    private AccessService $access;
    private ConfigService $config;

    public function __construct(AccessService $access, ConfigService $config)
    {
        $this->access = $access;
        $this->config = $config;
    }

    public function authenticate(array $payload, string $clientIp): void
    {
        $expectedToken = $this->config->get('OUTGOING_WEBHOOK_TOKEN');
        if ($expectedToken === null) {
            throw new AuthenticationException('server_not_configured', 500);
        }

        $authInfo = $this->access->extractAuthInfo($payload);
        if (!hash_equals($expectedToken, $authInfo['token'])) {
            throw new AuthenticationException('invalid_token', 403);
        }

        $allowedIps = $this->access->getAllowedIps();
        if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
            throw new AuthenticationException('ip_not_allowed', 403);
        }
    }

    public function extractAuthInfo(array $payload): array
    {
        return $this->access->extractAuthInfo($payload);
    }
}
