<?php

class AccessConfigService
{
    private const CONFIG_PATH = __DIR__ . '/../config/app-access.php';
    private const MAX_LIST_ITEMS = 200;

    private AppLogger $logger;

    public function __construct(AppLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return array{config:array{global_enabled:bool,deny_direct:bool,super_admin_id:string,allowed_users:array,allowed_departments:array},config_error:bool}
     */
    public function getConfig(): array
    {
        $configError = false;
        $config = [];

        try {
            $config = include self::CONFIG_PATH;
            if (!is_array($config)) {
                $configError = true;
                $config = [];
            }
        } catch (Throwable $exception) {
            $configError = true;
            $config = [];
        }

        $normalized = $this->normalizeConfig($config);

        if ($configError) {
            $this->logger->log('access-config', [
                'status' => 'error',
                'message' => 'access config error',
                'config_path' => self::CONFIG_PATH,
            ]);
        }

        return [
            'config' => $normalized,
            'config_error' => $configError,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success:bool,config:array{global_enabled:bool,deny_direct:bool,super_admin_id:string,allowed_users:array,allowed_departments:array},error_message:string}
     */
    public function saveConfig(array $config): array
    {
        $normalized = $this->normalizeConfig($config);

        $payload = "<?php\n\nreturn " . var_export($normalized, true) . ";\n";
        $written = @file_put_contents(self::CONFIG_PATH, $payload, LOCK_EX);

        if ($written === false) {
            $this->logger->log('access-config', [
                'status' => 'error',
                'message' => 'access config write failed',
                'config_path' => self::CONFIG_PATH,
            ]);

            return [
                'success' => false,
                'config' => $normalized,
                'error_message' => 'Не удалось сохранить конфигурацию доступа.',
            ];
        }

        return [
            'success' => true,
            'config' => $normalized,
            'error_message' => '',
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{global_enabled:bool,deny_direct:bool,super_admin_id:string,allowed_users:array,allowed_departments:array}
     */
    public function normalizeConfig(array $config): array
    {
        $globalEnabled = $this->normalizeBool($config['global_enabled'] ?? true);
        $denyDirect = $this->normalizeBool($config['deny_direct'] ?? false);
        $superAdminId = $this->normalizeId($config['super_admin_id'] ?? '');
        $allowedUsers = $this->normalizeIdList($config['allowed_users'] ?? []);
        $allowedDepartments = $this->normalizeIdList($config['allowed_departments'] ?? []);

        return [
            'global_enabled' => $globalEnabled,
            'deny_direct' => $denyDirect,
            'super_admin_id' => $superAdminId,
            'allowed_users' => $allowedUsers,
            'allowed_departments' => $allowedDepartments,
        ];
    }

    private function normalizeBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
        }

        return false;
    }

    private function normalizeId($value): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) (int) $value;
        } elseif (is_string($value)) {
            $value = trim($value);
        } else {
            $value = '';
        }

        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return '';
        }

        return ltrim($value, '0') ?: '0';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeIdList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $normalized = $this->normalizeId($item);
            if ($normalized === '') {
                continue;
            }
            $result[$normalized] = $normalized;
            if (count($result) >= self::MAX_LIST_ITEMS) {
                break;
            }
        }

        return array_values($result);
    }
}
