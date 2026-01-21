<?php

class JsonResponseService
{
    private const DEFAULT_STATUS = 'ok';
    private const DEFAULT_ERROR_MESSAGE = '';

    private string $logTag;
    private int $encodingFlags;

    public function __construct(string $logTag = 'app-response')
    {
        $this->logTag = $logTag;
        $this->encodingFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    }

    public function normalizeString($value, string $fallback = ''): string
    {
        return is_string($value) ? $value : $fallback;
    }

    public function normalizeBool($value, bool $fallback = false): bool
    {
        return is_bool($value) ? $value : $fallback;
    }

    public function normalizeOptionalBool($value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    public function send(array $payload): void
    {
        $payload = $this->ensureDefaults($payload);
        $this->setHeaders();
        echo $this->encode($payload);
    }

    public function logBootstrapOutput(string $output): void
    {
        if ($output === '') {
            return;
        }

        $this->logError('bootstrap output suppressed');
    }

    public function logError(string $message): void
    {
        if (function_exists('AddMessage2Log')) {
            AddMessage2Log($message, $this->logTag);
            return;
        }

        error_log($this->logTag . ': ' . $message);
    }

    private function setHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
    }

    private function ensureDefaults(array $payload): array
    {
        if (!array_key_exists('status', $payload)) {
            $payload['status'] = self::DEFAULT_STATUS;
        }

        if (!array_key_exists('error_message', $payload)) {
            $payload['error_message'] = self::DEFAULT_ERROR_MESSAGE;
        }

        return $payload;
    }

    private function encode(array $payload): string
    {
        if (defined('JSON_THROW_ON_ERROR')) {
            try {
                return json_encode($payload, $this->encodingFlags | JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $this->logError('json_encode failed: ' . $exception->getMessage());
            }
        } else {
            $encoded = json_encode($payload, $this->encodingFlags);
            if ($encoded !== false) {
                return $encoded;
            }

            $this->logError('json_encode failed: ' . json_last_error_msg());
        }

        $fallback = [
            'status' => 'error',
            'error_message' => 'Response encoding error.',
        ];

        $encodedFallback = json_encode($fallback, $this->encodingFlags);
        if ($encodedFallback !== false) {
            return $encodedFallback;
        }

        return '{"status":"error","error_message":"Response encoding error."}';
    }
}
