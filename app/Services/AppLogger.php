<?php

class AppLogger
{
    private const DEFAULT_CHANNEL = 'app-open';
    private const DATE_FORMAT = 'Y-m-d H:i';
    private const TIMEZONE = 'Europe/Minsk';

    /**
     * @param array<string, string|int|float|bool|null> $context
     */
    public function log(string $channel, array $context): void
    {
        $normalizedChannel = $this->sanitizeChannel($channel);
        if ($normalizedChannel === '') {
            $normalizedChannel = self::DEFAULT_CHANNEL;
        }

        $timestamp = $this->getBrestTimestamp();
        $context = $this->normalizeContext($context, $timestamp);
        $logLine = $this->formatLogLine($timestamp, $context);
        $logPath = $this->getLogPath($timestamp, $normalizedChannel);

        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $written = @file_put_contents($logPath, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            $error = error_get_last();
            $errorMessage = is_array($error) && isset($error['message']) ? $error['message'] : 'unknown error';
            error_log('app log write failed: ' . $errorMessage . ' | path=' . $logPath);
        }
    }

    private function sanitizeChannel(string $channel): string
    {
        $channel = trim($channel);
        $channel = preg_replace('/[^a-z0-9\-_\.]+/i', '-', $channel) ?? '';
        return trim($channel, '-');
    }

    /**
     * @param array<string, string|int|float|bool|null> $context
     * @return array<string, string>
     */
    private function normalizeContext(array $context, string $timestamp): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            $normalized[(string) $key] = $this->sanitizeLogValue($this->stringifyValue($value));
        }

        $normalized['timestamp'] = $timestamp;
        $normalized['portal_id'] = $normalized['portal_id'] ?? '';
        $normalized['status'] = $normalized['status'] ?? 'unknown';
        $normalized['message'] = $normalized['message'] ?? '';
        $normalized['access_context'] = $normalized['access_context'] ?? '';

        return $normalized;
    }

    /**
     * @param array<string, string> $context
     */
    private function formatLogLine(string $timestamp, array $context): string
    {
        $orderedKeys = [
            'user_id',
            'name',
            'last_name',
            'portal_id',
            'is_admin',
            'admin_source',
            'access',
            'department',
            'status',
            'message',
            'access_mode',
            'access_context',
            'access_decision',
            'access_reason',
            'auth_source',
            'auth_domain',
        ];

        $parts = [$timestamp];

        foreach ($orderedKeys as $key) {
            if (array_key_exists($key, $context)) {
                $parts[] = $key . '=' . $context[$key];
                unset($context[$key]);
            }
        }

        foreach ($context as $key => $value) {
            if ($key === 'timestamp') {
                continue;
            }
            $parts[] = $key . '=' . $value;
        }

        return implode(' | ', $parts);
    }

    private function getBrestTimestamp(): string
    {
        $dateTime = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
        return $dateTime->format(self::DATE_FORMAT) . ' (UTC+3, Brest)';
    }

    private function getLogPath(string $timestamp, string $channel): string
    {
        $datePart = $this->extractLogDate($timestamp);
        if ($datePart === null) {
            $datePart = (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
        }

        $pathDate = str_replace('-', '/', $datePart);
        $rootPath = dirname(__DIR__, 2);

        return $rootPath . '/logs-apps/' . $pathDate . '/' . $channel . '.log';
    }

    private function extractLogDate(string $timestamp): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $timestamp, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function sanitizeLogValue(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return trim($value);
    }

    /**
     * @param string|int|float|bool|null $value
     */
    private function stringifyValue($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
