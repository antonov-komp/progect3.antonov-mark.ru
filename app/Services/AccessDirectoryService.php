<?php

class AccessDirectoryService
{
    private const CACHE_TTL = 3600;

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

        $result = $this->callBitrix('user.get', [
            'FILTER' => ['ACTIVE' => 'Y'],
            'SELECT' => ['ID', 'NAME', 'LAST_NAME'],
        ]);

        if (!empty($result['error'])) {
            $this->logger->log('access-directory', [
                'status' => 'error',
                'message' => 'user.get failed',
                'error' => $result['error'] ?? '',
            ]);

            return [
                'status' => 'error',
                'message' => 'Не удалось загрузить пользователей.',
                'items' => [],
            ];
        }

        $items = [];
        foreach ($result['result'] ?? [] as $user) {
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

        $result = $this->callBitrix('department.get');

        if (!empty($result['error'])) {
            $this->logger->log('access-directory', [
                'status' => 'error',
                'message' => 'department.get failed',
                'error' => $result['error'] ?? '',
            ]);

            return [
                'status' => 'error',
                'message' => 'Не удалось загрузить отделы.',
                'items' => [],
            ];
        }

        $items = [];
        foreach ($result['result'] ?? [] as $department) {
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
