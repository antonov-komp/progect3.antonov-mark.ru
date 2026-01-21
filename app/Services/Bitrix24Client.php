<?php

class Bitrix24Client
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;

    /**
     * Вызов Bitrix24 REST API с поддержкой CRest и auth-контекста.
     *
     * @param string $method Метод Bitrix24 REST API.
     * @param array<string, mixed> $params Параметры запроса.
     * @param array{auth_id?:string,domain?:string} $authContext Контекст авторизации.
     * @return array{result:array|bool|null,error:string,error_information:string}
     */
    public function call(string $method, array $params = [], array $authContext = []): array
    {
        if ($this->hasTokenContext($authContext)) {
            return $this->callWithToken($method, $params, $authContext);
        }

        return $this->callWithCrest($method, $params);
    }

    private function hasTokenContext(array $authContext): bool
    {
        $authId = isset($authContext['auth_id']) ? trim((string) $authContext['auth_id']) : '';
        $domain = isset($authContext['domain']) ? trim((string) $authContext['domain']) : '';

        return $authId !== '' && $domain !== '';
    }

    private function callWithCrest(string $method, array $params): array
    {
        $response = CRest::call($method, $params);

        return $this->normalizeResponse($response, $method, 'crest');
    }

    private function callWithToken(string $method, array $params, array $authContext): array
    {
        if (!function_exists('curl_init')) {
            return $this->logAndReturnError('error_php_lib_curl', 'need install curl lib', $method, 'token');
        }

        $domain = trim((string) ($authContext['domain'] ?? ''));
        $authId = trim((string) ($authContext['auth_id'] ?? ''));

        if (!$this->isDomainValid($domain)) {
            return $this->logAndReturnError('invalid_domain', 'domain has invalid characters', $method, 'token');
        }

        $url = 'https://' . $domain . '/rest/' . $method . '.json';
        $params['auth'] = $authId;
        $postFields = http_build_query($params);

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl, CURLOPT_USERAGENT, 'Bitrix24 CRest PHP ' . CRest::VERSION);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            curl_setopt($curl, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);
            if (defined('C_REST_IGNORE_SSL') && C_REST_IGNORE_SSL === true) {
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            }

            $out = curl_exec($curl);
            $info = curl_getinfo($curl);
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($out === false || $curlError !== '') {
                return $this->logAndReturnError(
                    'curl_error',
                    $curlError !== '' ? $curlError : 'unknown curl error',
                    $method,
                    'token'
                );
            }

            $decoded = $this->decodeJsonResponse($out);
            if ($decoded === null) {
                return $this->logAndReturnError('invalid_response', 'invalid json response', $method, 'token');
            }

            if (!empty($info['http_code']) && (int) $info['http_code'] >= 400) {
                $decoded['error'] = $decoded['error'] ?? 'http_error';
                $decoded['error_information'] = $decoded['error_information'] ?? ('HTTP ' . $info['http_code']);
            }

            return $this->normalizeResponse($decoded, $method, 'token');
        } catch (Throwable $exception) {
            return $this->logAndReturnError('exception', $exception->getMessage(), $method, 'token');
        }
    }

    private function normalizeResponse($response, string $method, string $source): array
    {
        if (!is_array($response)) {
            return $this->logAndReturnError('invalid_response', 'response is not array', $method, $source);
        }

        $normalized = [
            'result' => $response['result'] ?? null,
            'error' => isset($response['error']) ? (string) $response['error'] : '',
            'error_information' => isset($response['error_information']) ? (string) $response['error_information'] : '',
            'next' => isset($response['next']) && is_numeric($response['next']) ? (int) $response['next'] : null,
            'total' => isset($response['total']) && is_numeric($response['total']) ? (int) $response['total'] : null,
        ];

        if ($normalized['error'] !== '') {
            $this->logError('bitrix24 rest error', [
                'method' => $method,
                'source' => $source,
                'error' => $normalized['error'],
                'error_information' => $normalized['error_information'],
            ]);
        }

        return $normalized;
    }

    private function decodeJsonResponse(string $payload): ?array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function isDomainValid(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        return preg_match('/^[a-z0-9\.\-]+$/i', $domain) === 1;
    }

    private function logAndReturnError(string $error, string $info, string $method, string $source): array
    {
        $this->logError('bitrix24 rest error', [
            'method' => $method,
            'source' => $source,
            'error' => $error,
            'error_information' => $info,
        ]);

        return [
            'result' => null,
            'error' => $error,
            'error_information' => $info,
        ];
    }

    /**
     * Логируем ошибки REST, не прерывая сценарий.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    private function logError(string $message, array $context = []): void
    {
        $payload = $message;
        if (!empty($context)) {
            $payload .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (function_exists('AddMessage2Log')) {
            AddMessage2Log($payload, 'bitrix24-client');
            return;
        }

        error_log($payload);
    }
}
