<?php
declare(strict_types=1);

class ErrorService
{
    public function log(string $message, array $context = []): void
    {
        outgoingWebhookLogError($message, $context);
    }
}
