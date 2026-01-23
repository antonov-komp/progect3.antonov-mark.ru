<?php
declare(strict_types=1);

class QueueStepLogger
{
    private string $logPath;

    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
    }

    public function started(array $context): void
    {
        $this->write('started', $context);
    }

    public function finished(array $context): void
    {
        $this->write('finished', $context);
    }

    private function write(string $step, array $context): void
    {
        $entry = array_merge([
            'loggedAt' => outgoingWebhookNow(),
            'step' => $step,
        ], $context);

        outgoingWebhookAppendLine($this->logPath, json_encode($entry, JSON_UNESCAPED_SLASHES));
    }
}
