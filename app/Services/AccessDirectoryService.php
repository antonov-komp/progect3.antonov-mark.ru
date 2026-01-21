<?php

class AccessDirectoryService
{
    private const CACHE_TTL = 3600;
    private const MAX_BATCHES = 200;

    private AccessContextService $accessContextService;
    private Bitrix24Client $bitrix24Client;
    private AppLogger $logger;
    private string $cacheDir;

    public function __construct(
        AccessContextService $accessContextService,
        Bitrix24Client $bitrix24Client,
        AppLogger $logger
    ) {
        $this->accessContextService = $accessContextService;
        $this->bitrix24Client = $bitrix24Client;
        $this->logger = $logger;
        $rootPath = dirname(__DIR__, 2);
        $this->cacheDir = $rootPath . '/tmp/cache';
    }

    /**
     * @return array{status:string,message:string,items:array<int, array{id:string,name:string,last_name:string,full_name:string}>}
     */
    public function fetchUsers(): array
    {
        $cacheKey = $this->getCacheKey('users');
        $cachedItems = $this->readCache($cacheKey);
        if ($cachedItems !== null) {
            return [
                'status' => 'ok',
                'message' => '',
                'items' => $cachedItems,
            ];
        }

        $rawUsers = $this->fetchAllPages('user.get', [
            'FILTER' => ['ACTIVE' => 'Y'],
            'SELECT' => ['ID', 'NAME', 'LAST_NAME'],
        ], 'users');

        if ($rawUsers['status'] === 'error') {
            $this->logger->log('access-directory', [
                'status' => 'error',
                'message' => 'user.get failed',
                'error' => $rawUsers['error'] ?? '',
            ]);

            return [
                'status' => 'error',
                'message' => 'Не удалось загрузить пользователей.',
                'items' => [],
            ];
        }

        $items = [];
        foreach ($rawUsers['items'] as $user) {
            if (!is_array($user)) {
                continue;
            }
            $id = isset($user['ID']) ? (string) $user['ID'] : '';
            if ($id === '') {
                continue;
            }
            $name = isset($user['NAME']) ? (string) $user['NAME'] : '';
            $lastName = isset($user['LAST_NAME']) ? (string) $user['LAST_NAME'] : '';
            $fullName = trim($name . ' ' . $lastName);
            if ($fullName === '') {
                $fullName = 'ID ' . $id;
            }
            $items[] = [
                'id' => $id,
                'name' => $name,
                'last_name' => $lastName,
                'full_name' => $fullName,
            ];
        }

        $this->writeCache($cacheKey, $items);

        return [
            'status' => 'ok',
            'message' => '',
            'items' => $items,
        ];
    }

    /**
     * @return array{status:string,message:string,items:array<int, array{id:string,name:string}>}
     */
    public function fetchDepartments(): array
    {
        $cacheKey = $this->getCacheKey('departments');
        $cachedItems = $this->readCache($cacheKey);
        if ($cachedItems !== null) {
            return [
                'status' => 'ok',
                'message' => '',
                'items' => $cachedItems,
            ];
        }

        $rawDepartments = $this->fetchAllPages('department.get', [], 'departments');

        if ($rawDepartments['status'] === 'error') {
            $this->logger->log('access-directory', [
                'status' => 'error',
                'message' => 'department.get failed',
                'error' => $rawDepartments['error'] ?? '',
            ]);

            return [
                'status' => 'error',
                'message' => 'Не удалось загрузить отделы.',
                'items' => [],
            ];
        }

        $items = [];
        foreach ($rawDepartments['items'] as $department) {
            if (!is_array($department)) {
                continue;
            }
            $id = isset($department['ID']) ? (string) $department['ID'] : '';
            if ($id === '') {
                continue;
            }
            $name = isset($department['NAME']) ? (string) $department['NAME'] : '';
            $items[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ('ID ' . $id),
            ];
        }

        $this->writeCache($cacheKey, $items);

        return [
            'status' => 'ok',
            'message' => '',
            'items' => $items,
        ];
    }

    private function callBitrix(string $method, array $params = []): array
    {
        $authContext = $this->accessContextService->getAuthContext();
        return $this->bitrix24Client->call($method, $params, $authContext);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status:string,message:string,items:array,error:string}
     */
    private function fetchAllPages(string $method, array $params, string $label): array
    {
        $items = [];
        $start = 0;
        $iterations = 0;

        while (true) {
            $batchParams = $params;
            $batchParams['START'] = $start;
            $result = $this->callBitrix($method, $batchParams);

            if (!empty($result['error'])) {
                return [
                    'status' => 'error',
                    'message' => 'bitrix_error',
                    'items' => [],
                    'error' => (string) ($result['error'] ?? ''),
                ];
            }

            $batch = $result['result'] ?? [];
            if (is_array($batch)) {
                $items = array_merge($items, $batch);
            }

            $next = isset($result['next']) && is_numeric($result['next']) ? (int) $result['next'] : null;
            if ($next === null) {
                break;
            }

            if ($next <= $start) {
                $this->logger->log('access-directory', [
                    'status' => 'error',
                    'message' => 'invalid pagination cursor',
                    'label' => $label,
                    'next' => $next,
                    'start' => $start,
                ]);
                break;
            }

            $start = $next;
            $iterations++;
            if ($iterations >= self::MAX_BATCHES) {
                $this->logger->log('access-directory', [
                    'status' => 'error',
                    'message' => 'pagination limit reached',
                    'label' => $label,
                    'batches' => $iterations,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'pagination_limit',
                    'items' => [],
                    'error' => 'pagination_limit',
                ];
            }
        }

        return [
            'status' => 'ok',
            'message' => '',
            'items' => $items,
            'error' => '',
        ];
    }

    private function getCacheKey(string $prefix): string
    {
        $authContext = $this->accessContextService->getAuthContext();
        $domain = isset($authContext['domain']) ? (string) $authContext['domain'] : '';
        $suffix = $domain !== '' ? $domain : 'unknown';
        $suffix = preg_replace('/[^a-z0-9\.\-]+/i', '-', $suffix) ?? 'unknown';
        $suffix = trim($suffix, '-');

        return $prefix . '-' . ($suffix !== '' ? $suffix : 'unknown');
    }

    private function readCache(string $key): ?array
    {
        $path = $this->getCachePath($key);
        if (!is_file($path)) {
            return null;
        }

        $payload = @file_get_contents($path);
        if ($payload === false) {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $timestamp = isset($decoded['timestamp']) ? (int) $decoded['timestamp'] : 0;
        if ($timestamp <= 0 || (time() - $timestamp) > self::CACHE_TTL) {
            return null;
        }

        $items = $decoded['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        return $items;
    }

    /**
     * @param array<int, array<string, string>> $items
     */
    private function writeCache(string $key, array $items): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        $payload = json_encode([
            'timestamp' => time(),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        $written = @file_put_contents($this->getCachePath($key), $payload, LOCK_EX);
        if ($written === false) {
            $this->logger->log('access-directory', [
                'status' => 'error',
                'message' => 'cache write failed',
                'cache_key' => $key,
            ]);
        }
    }

    private function getCachePath(string $key): string
    {
        return $this->cacheDir . '/access-directory-' . $key . '.json';
    }
}
