<?php
declare(strict_types=1);

/**
 * Валидатор HTTP-запросов
 * 
 * Ответственность:
 * - Валидация HTTP-метода
 * - Валидация размера payload
 * - Валидация структуры payload
 */
class RequestValidator
{
    private int $maxBytes;

    public function __construct(int $maxBytes = OUTGOING_WEBHOOK_MAX_BYTES)
    {
        $this->maxBytes = $maxBytes;
    }

    public function validateMethod(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new InvalidRequestException('method_not_allowed', 405);
        }
    }

    public function validateContentLength(): void
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $this->maxBytes) {
            throw new InvalidRequestException('payload_too_large', 413);
        }
    }

    public function validatePayload(array $payload): void
    {
        if (empty($payload)) {
            throw new InvalidRequestException('invalid_payload', 400);
        }
    }
}
