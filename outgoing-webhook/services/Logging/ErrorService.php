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

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Fallback при ошибке кодирования JSON
            $json = json_encode([
                'loggedAt' => $this->request->now(),
                'message' => $message,
                'context' => 'json_encode_failed',
                'original_context_keys' => array_keys($context),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->filesystem->appendLine($logPath, $json);
        
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($contextJson === false) {
            $contextJson = 'json_encode_failed';
        }
        error_log('[outgoing-webhook] ' . $message . ' ' . $contextJson);
    }
}
