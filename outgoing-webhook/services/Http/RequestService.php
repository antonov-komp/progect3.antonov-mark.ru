<?php
declare(strict_types=1);

class RequestService
{
    private ConfigService $config;

    public function __construct(ConfigService $config)
    {
        $this->config = $config;
    }

    public function readPayload(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $rawBody = file_get_contents('php://input');

        if (stripos($contentType, 'application/json') !== false && $rawBody !== false) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        if ($rawBody !== false && $rawBody !== '') {
            parse_str($rawBody, $parsed);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
        }

        return [];
    }

    public function jsonResponse(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    public function normalizeEventType(?string $event): string
    {
        $event = $event ?? '';
        $event = strtoupper(trim(str_replace(' ', '', $event)));
        $event = preg_replace('/[^A-Z0-9_]/', '', $event);
        return $event !== '' ? $event : 'UNKNOWN';
    }

    public function resolveAbsoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (!str_starts_with($url, '/')) {
            return $url;
        }

        $endpoint = $this->config->getClientEndpoint();
        if (!is_string($endpoint) || $endpoint === '') {
            return $url;
        }

        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? null;
        if ($host === null) {
            return $url;
        }

        return $scheme . '://' . $host . $url;
    }

    public function getFirstValue(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (is_array($key)) {
                $value = $data;
                $found = true;
                foreach ($key as $path) {
                    if (!is_array($value) || !array_key_exists($path, $value)) {
                        $found = false;
                        break;
                    }
                    $value = $value[$path];
                }
                if ($found && $value !== null && $value !== '') {
                    return $value;
                }
                continue;
            }

            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    public function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function now(): string
    {
        return date('c');
    }
}
