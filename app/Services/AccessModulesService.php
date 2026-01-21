<?php

class AccessModulesService
{
    private const CONFIG_PATH = __DIR__ . '/../config/app-modules-access.php';
    private const MAX_LIST_ITEMS = 200;
    private const MAX_MODULES = 50;

    private AppLogger $logger;

    public function __construct(AppLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return array{config:array{modules:array<int,array<string,mixed>>},config_error:bool}
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
            $this->logger->log('access-modules', [
                'status' => 'error',
                'message' => 'access modules config error',
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
     * @return array{success:bool,config:array{modules:array<int,array<string,mixed>>},error_message:string}
     */
    public function saveConfig(array $config): array
    {
        $normalized = $this->normalizeConfig($config);

        $payload = "<?php\n\nreturn " . var_export($normalized, true) . ";\n";
        $written = @file_put_contents(self::CONFIG_PATH, $payload, LOCK_EX);

        if ($written === false) {
            $this->logger->log('access-modules', [
                'status' => 'error',
                'message' => 'access modules config write failed',
                'config_path' => self::CONFIG_PATH,
            ]);

            return [
                'success' => false,
                'config' => $normalized,
                'error_message' => 'Не удалось сохранить конфигурацию модулей.',
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
     * @return array{modules:array<int,array<string,mixed>>}
     */
    public function normalizeConfig(array $config): array
    {
        $modules = [];
        if (isset($config['modules']) && is_array($config['modules'])) {
            foreach ($config['modules'] as $module) {
                if (count($modules) >= self::MAX_MODULES) {
                    break;
                }
                $normalized = $this->normalizeModule($module);
                if ($normalized === null) {
                    continue;
                }
                $modules[] = $normalized;
            }
        }

        if ($modules === []) {
            $modules = $this->getDefaultModules();
        }

        return [
            'modules' => $modules,
        ];
    }

    /**
     * @param mixed $module
     * @return array<string, mixed>|null
     */
    private function normalizeModule($module): ?array
    {
        if (!is_array($module)) {
            return null;
        }

        $key = $this->normalizeString($module['key'] ?? '');
        if ($key === '') {
            return null;
        }

        $enabled = $this->normalizeBool($module['enabled'] ?? true);
        if (isset($module['mode'])) {
            $mode = $this->normalizeString($module['mode'] ?? '');
            if ($mode === 'active') {
                $enabled = true;
            } elseif ($mode === 'inactive') {
                $enabled = false;
            }
        }

        return [
            'key' => $key,
            'title' => $this->normalizeString($module['title'] ?? ''),
            'subtitle' => $this->normalizeString($module['subtitle'] ?? ''),
            'icon' => $this->normalizeString($module['icon'] ?? ''),
            'route' => $this->normalizeString($module['route'] ?? ''),
            'enabled' => $enabled,
            'allowed_users' => $this->normalizeIdList($module['allowed_users'] ?? []),
            'allowed_departments' => $this->normalizeIdList($module['allowed_departments'] ?? []),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultModules(): array
    {
        return [
            [
                'key' => 'module_reports',
                'title' => 'Отчеты',
                'subtitle' => 'Сводная аналитика и показатели',
                'icon' => 'chart',
                'route' => '/modules/reports',
                'enabled' => true,
                'allowed_users' => [],
                'allowed_departments' => [],
            ],
            [
                'key' => 'module_requests',
                'title' => 'Заявки',
                'subtitle' => 'Создание и контроль обращений',
                'icon' => 'inbox',
                'route' => '/modules/requests',
                'enabled' => true,
                'allowed_users' => [],
                'allowed_departments' => [],
            ],
            [
                'key' => 'module_documents',
                'title' => 'Документы',
                'subtitle' => 'Шаблоны, согласования, версии',
                'icon' => 'file',
                'route' => '/modules/documents',
                'enabled' => true,
                'allowed_users' => [],
                'allowed_departments' => [],
            ],
            [
                'key' => 'module_team',
                'title' => 'Команда',
                'subtitle' => 'Сотрудники и роли',
                'icon' => 'users',
                'route' => '/modules/team',
                'enabled' => true,
                'allowed_users' => [],
                'allowed_departments' => [],
            ],
            [
                'key' => 'module_settings',
                'title' => 'Настройки',
                'subtitle' => 'Параметры и интеграции',
                'icon' => 'settings',
                'route' => '/modules/settings',
                'enabled' => true,
                'allowed_users' => [],
                'allowed_departments' => [],
            ],
        ];
    }

    private function normalizeString($value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
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
