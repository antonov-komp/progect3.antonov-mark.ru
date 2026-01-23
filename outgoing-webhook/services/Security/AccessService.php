<?php
declare(strict_types=1);

class AccessService
{
    private ConfigService $config;

    public function __construct(ConfigService $config)
    {
        $this->config = $config;
    }

    public function getAllowedIps(): array
    {
        $setting = $this->config->get('OUTGOING_WEBHOOK_ALLOWED_IPS');
        if ($setting === null) {
            return [];
        }

        if (is_string($setting)) {
            $items = array_filter(array_map('trim', explode(',', $setting)));
            return array_values(array_unique($items));
        }

        if (is_array($setting)) {
            $items = [];
            foreach ($setting as $value) {
                if (is_string($value)) {
                    $value = trim($value);
                    if ($value !== '') {
                        $items[] = $value;
                    }
                }
            }
            return array_values(array_unique($items));
        }

        return [];
    }

    public function extractAuthToken(array $payload): string
    {
        if (isset($payload['token']) && is_string($payload['token'])) {
            return $payload['token'];
        }

        if (isset($payload['auth']) && is_array($payload['auth'])) {
            $auth = $payload['auth'];
            if (isset($auth['application_token']) && is_string($auth['application_token'])) {
                return $auth['application_token'];
            }
            if (isset($auth['app_token']) && is_string($auth['app_token'])) {
                return $auth['app_token'];
            }
        }

        return '';
    }

    public function extractAuthInfo(array $payload): array
    {
        if (isset($payload['token']) && is_string($payload['token'])) {
            return ['token' => $payload['token'], 'source' => 'token'];
        }

        if (isset($payload['auth']) && is_array($payload['auth'])) {
            $auth = $payload['auth'];
            if (isset($auth['application_token']) && is_string($auth['application_token'])) {
                return ['token' => $auth['application_token'], 'source' => 'auth.application_token'];
            }
            if (isset($auth['app_token']) && is_string($auth['app_token'])) {
                return ['token' => $auth['app_token'], 'source' => 'auth.app_token'];
            }
        }

        return ['token' => '', 'source' => 'none'];
    }
}
