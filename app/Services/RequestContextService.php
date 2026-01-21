<?php

class RequestContextService
{
    private const CONTEXT_KEYS = [
        'AUTH_ID',
        'DOMAIN',
        'member_id',
        'PLACEMENT',
        'PLACEMENT_OPTIONS',
        'IFRAME',
        'B24_FRAME',
    ];

    private array $request;
    private array $session;

    public function __construct(array &$request, array &$session)
    {
        $this->request = &$request;
        $this->session = &$session;
    }

    public function getContext(): array
    {
        $context = [];

        foreach (self::CONTEXT_KEYS as $key) {
            $value = $this->sanitizeRequestValue($this->request[$key] ?? '');
            if ($value !== '') {
                $this->session[$key] = $value;
            }

            $context[$key] = $value !== '' ? $value : $this->sanitizeRequestValue($this->session[$key] ?? '');
        }

        return $context;
    }

    private function sanitizeRequestValue($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_scalar($value)) {
            $value = (string) $value;
        } else {
            return '';
        }

        $value = str_replace(["\r", "\n"], '', $value);
        return trim($value);
    }
}
