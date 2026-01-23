<?php
declare(strict_types=1);

class RestService
{
    private Bitrix24Client $client;
    private ConfigService $config;
    private ErrorService $errors;

    public function __construct(Bitrix24Client $client, ConfigService $config, ErrorService $errors)
    {
        $this->client = $client;
        $this->config = $config;
        $this->errors = $errors;
    }

    public function call(string $method, array $params = []): array
    {
        $retries = (int) ($this->config->get('OUTGOING_WEBHOOK_REST_RETRIES', '0') ?? 0);
        $delayMs = (int) ($this->config->get('OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS', '0') ?? 0);
        $attempt = 0;

        do {
            $attempt++;
            $result = $this->client->call($method, $params);

            if (empty($result['error'])) {
                return $result;
            }

            if ($attempt <= $retries) {
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
                continue;
            }

            $this->errors->log('REST call failed', [
                'method' => $method,
                'error' => $result['error'],
                'error_information' => $result['error_information'] ?? null,
            ]);

            return $result;
        } while ($attempt <= $retries);

        return $result ?? ['error' => 'unknown'];
    }
}
