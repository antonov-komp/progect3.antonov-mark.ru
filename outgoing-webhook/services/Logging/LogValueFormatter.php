<?php
declare(strict_types=1);

class LogValueFormatter
{
    public function maskValue(string $value): string
    {
        $suffix = substr($value, -4);
        return '****' . $suffix;
    }

    public function maskPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->maskPayload($value);
                continue;
            }

            if (is_string($value) && in_array(strtolower((string) $key), ['token'], true)) {
                $payload[$key] = $this->maskValue($value);
            }
        }

        return $payload;
    }

    public function normalize($value): string
    {
        if ($value === null) {
            return 'unknown';
        }

        $text = trim((string) $value);
        if ($text === '') {
            return 'unknown';
        }

        $text = preg_replace('/\s+/u', ' ', $text);
        return $text ?? 'unknown';
    }
}
