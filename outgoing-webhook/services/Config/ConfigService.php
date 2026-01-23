<?php
declare(strict_types=1);

class ConfigService
{
    public const MAX_BYTES = 2097152;

    private ?array $configCache = null;
    private ?string $clientEndpointCache = null;

    public function getEnv(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }

    public function getConfig(): array
    {
        if ($this->configCache !== null) {
            return $this->configCache;
        }

        $configPath = dirname(__DIR__, 2) . '/config.local.php';
        if (file_exists($configPath)) {
            $loaded = require $configPath;
            if (is_array($loaded)) {
                $this->configCache = $loaded;
                return $this->configCache;
            }
        }

        $this->configCache = [];
        return $this->configCache;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $env = $this->getEnv($key);
        if ($env !== null) {
            return $env;
        }

        $config = $this->getConfig();
        if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
            return $config[$key];
        }

        return $default;
    }

    public function getArray(string $key): array
    {
        $env = $this->getEnv($key);
        if ($env !== null) {
            return array_values(array_filter(array_map('trim', explode(',', $env))));
        }

        $config = $this->getConfig();
        $value = $config[$key] ?? [];
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', array_map('strval', $value))));
        }

        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }

    public function getClientEndpoint(): ?string
    {
        if ($this->clientEndpointCache !== null) {
            return $this->clientEndpointCache;
        }

        $settingsPath = dirname(__DIR__, 2) . '/../app/settings.json';
        if (!file_exists($settingsPath)) {
            $this->clientEndpointCache = null;
            return null;
        }

        $data = json_decode((string) file_get_contents($settingsPath), true);
        if (!is_array($data)) {
            $this->clientEndpointCache = null;
            return null;
        }

        $endpoint = $data['client_endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            $this->clientEndpointCache = $endpoint;
            return $this->clientEndpointCache;
        }

        $this->clientEndpointCache = null;
        return null;
    }

    public function getMaxBytes(): int
    {
        return self::MAX_BYTES;
    }
}
