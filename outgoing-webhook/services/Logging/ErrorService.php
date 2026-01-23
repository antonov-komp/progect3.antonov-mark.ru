<?php
declare(strict_types=1);

class ErrorService
{
    private FilesystemService $filesystem;
    private RequestService $request;

    public function __construct(FilesystemService $filesystem, RequestService $request)
    {
        $this->filesystem = $filesystem;
        $this->request = $request;
    }

    public function log(string $message, array $context = []): void
    {
        $logPath = dirname(__DIR__, 2) . '/logs/errors/error-' . date('Ymd') . '.log';
        $entry = [
            'loggedAt' => $this->request->now(),
            'message' => $message,
            'context' => $context,
        ];

        $this->filesystem->appendLine($logPath, json_encode($entry, JSON_UNESCAPED_SLASHES));
        error_log('[outgoing-webhook] ' . $message . ' ' . json_encode($context));
    }
}
