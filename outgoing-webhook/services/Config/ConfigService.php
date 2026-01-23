<?php
declare(strict_types=1);

class ConfigService
{
    public function get(string $key, ?string $default = null): ?string
    {
        $env = outgoingWebhookGetEnv($key);
        if ($env !== null) {
            return $env;
        }

        $config = outgoingWebhookGetConfig();
        if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
            return $config[$key];
        }

        return $default;
    }

    public function getArray(string $key): array
    {
        $env = outgoingWebhookGetEnv($key);
        if ($env !== null) {
            return array_values(array_filter(array_map('trim', explode(',', $env))));
        }

        $config = outgoingWebhookGetConfig();
        $value = $config[$key] ?? [];
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', array_map('strval', $value))));
        }

        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }
}
