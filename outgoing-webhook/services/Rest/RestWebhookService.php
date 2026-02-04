<?php
declare(strict_types=1);

/**
 * REST-клиент для входящего вебхука Bitrix24.
 *
 * Используется только для Activity-потока: tasks.task.files.attach, disk.file.get,
 * crm.item.update / crm.deal.update. Основной REST-клиент с токеном приложения
 * остаётся без изменений.
 */
class RestWebhookService
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;
    private const RETRY_DELAY_SECONDS = 2;
    private const MAX_ATTEMPTS = 2; // первая попытка + один ретрай

    private ConfigService $config;
    private ErrorService $errors;

    public function __construct(ConfigService $config, ErrorService $errors)
    {
        $this->config = $config;
        $this->errors = $errors;
    }

    /**
     * Вызов REST через вебхук.
     *
     * @param string $method  Метод Bitrix24 (без .json)
     * @param array  $params  Параметры запроса
     * @param array  $context Контекст для логирования (taskId, dealId, activityType и т.п.)
     *
     * @return array{result:mixed,error:string|null,error_description?:string}|array{error:string}
     */
    public function call(string $method, array $params = [], array $context = []): array
    {
        $base = trim((string) ($this->config->get('B24_WEBHOOK_BASE') ?? ''));
        if ($base === '') {
            $this->errors->log('webhook-mode: base url not configured', [
                'method' => $method,
                'context' => $context,
            ]);
            return [
                'result' => null,
                'error' => 'webhook_base_not_configured',
                'error_description' => 'B24_WEBHOOK_BASE is empty',
            ];
        }

        $url = rtrim($base, '/') . '/' . $method;
        $payload = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $this->errors->log('webhook-mode: failed to encode payload', [
                'method' => $method,
                'context' => $context,
            ]);
            return [
                'result' => null,
                'error' => 'payload_encode_failed',
            ];
        }

        $attempt = 0;
        $response = null;

        do {
            $attempt++;
            $response = $this->sendRequest($url, $payload);

            $decoded = $this->decodeResponse($response['body']);
            $httpCode = $response['http_code'];

            $logPayload = [
                'mode' => 'webhook',
                'method' => $method,
                'http_code' => $httpCode,
                'attempt' => $attempt,
                'context' => $context,
            ];

            if ($decoded === null) {
                $this->errors->log('webhook-mode: invalid json response', $logPayload + [
                    'body' => $response['body'],
                ]);
                $decoded = [
                    'result' => null,
                    'error' => 'invalid_response',
                    'error_description' => 'Response is not valid JSON',
                ];
            }

            // Логируем статус (успех/ошибка)
            $this->errors->log('webhook-mode', $logPayload + [
                'error' => $decoded['error'] ?? null,
                'error_description' => $decoded['error_description'] ?? ($decoded['error_information'] ?? null),
            ]);

            if (!$this->shouldRetry($decoded) || $attempt >= self::MAX_ATTEMPTS) {
                return $decoded;
            }

            if (self::RETRY_DELAY_SECONDS > 0) {
                usleep(self::RETRY_DELAY_SECONDS * 1000000);
            }
        } while ($attempt < self::MAX_ATTEMPTS);

        return $response ?? ['error' => 'unknown_error'];
    }

    private function sendRequest(string $url, string $payload): array
    {
        if (!function_exists('curl_init')) {
            return [
                'http_code' => 0,
                'body' => '',
                'error' => 'curl_not_available',
            ];
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);

        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            $body = '';
        }

        return [
            'http_code' => $httpCode,
            'body' => $curlError !== '' ? $curlError : $body,
            'error' => $curlError !== '' ? $curlError : null,
        ];
    }

    private function decodeResponse(string $body): ?array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function shouldRetry(array $decoded): bool
    {
        $error = (string) ($decoded['error'] ?? '');
        $result = $decoded['result'] ?? null;

        if ($error === '') {
            return $result === null || $result === [];
        }

        return strtoupper($error) === 'ERROR_CORE';
    }
}
