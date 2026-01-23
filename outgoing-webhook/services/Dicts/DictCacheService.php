<?php
declare(strict_types=1);

class DictCacheService
{
    private RestService $rest;
    private ErrorService $errors;
    private string $dictsDir;

    public function __construct(RestService $rest, ErrorService $errors, string $dictsDir)
    {
        $this->rest = $rest;
        $this->errors = $errors;
        $this->dictsDir = $dictsDir;
    }

    public function read(string $path, int $ttlSeconds): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $contents = json_decode((string) file_get_contents($path), true);
        if (!is_array($contents)) {
            return null;
        }

        $cachedAt = $contents['cachedAt'] ?? null;
        if (!is_string($cachedAt)) {
            return null;
        }

        $age = time() - strtotime($cachedAt);
        if ($age > $ttlSeconds) {
            return null;
        }

        return $contents['data'] ?? null;
    }

    public function write(string $path, array $data): void
    {
        outgoingWebhookWriteJson($path, [
            'cachedAt' => outgoingWebhookNow(),
            'data' => $data,
        ]);
    }

    public function get(string $name, string $method, array $params, int $ttlSeconds): ?array
    {
        $dictPath = $this->dictsDir . '/' . $name . '.json';
        $cached = $this->read($dictPath, $ttlSeconds);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->rest->call($method, $params);
        if (!empty($result['error'])) {
            $this->errors->log('Dict REST error', [
                'method' => $method,
                'error' => $result['error'],
            ]);
            return null;
        }

        $data = $result['result'] ?? [];
        if (is_array($data)) {
            $this->write($dictPath, $data);
            return $data;
        }

        return null;
    }
}
