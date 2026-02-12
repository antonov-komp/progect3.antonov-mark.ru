<?php

/**
 * Сервис хранения настроек полей-встроек.
 *
 * Настройки хранятся в JSON:
 * - data/embed-settings/{section}_{fieldId}.json — для загрузки из модала настроек
 * - data/embed-settings/by_field/{entityId}_{safeFieldName}.json — для handler (iframe)
 */
class EmbedFieldSettingsService
{
    private static function getSettingsDir(): string
    {
        $root = dirname(__DIR__, 2);
        return $root . '/data/embed-settings';
    }

    private static function getByFieldDir(): string
    {
        return self::getSettingsDir() . '/by_field';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $section, int $fieldId): ?array
    {
        $path = self::getSettingsPath($section, $fieldId);
        return self::readJsonFile($path);
    }

    /**
     * Получение настроек по ENTITY_ID и FIELD_NAME (для handler в iframe).
     * Сначала ищет в by_field/; при отсутствии — через $resolver по section+fieldId.
     *
     * @param callable(string, string): array{0: string, 1: int}|null|null $resolver Опционально: (entityId, fieldName) -> [section, fieldId]
     * @return array<string, mixed>|null
     */
    public function getByEntityField(string $entityId, string $fieldName, ?callable $resolver = null): ?array
    {
        $path = self::getPathByEntityField($entityId, $fieldName);
        $settings = self::readJsonFile($path);
        if ($settings !== null) {
            return $settings;
        }
        if ($resolver !== null) {
            $resolved = $resolver($entityId, $fieldName);
            if (is_array($resolved) && count($resolved) >= 2) {
                $settings = $this->get($resolved[0], (int) $resolved[1]);
                if ($settings !== null) {
                    return $settings;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $settings
     * @param string|null $entityIdOverride Для section=smart — явный entityId (CRM_123) для by_field
     */
    public function save(string $section, int $fieldId, array $settings, ?string $fieldName = null, ?string $entityIdOverride = null): bool
    {
        $dir = self::getSettingsDir();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true)) {
                return false;
            }
        }
        if (!is_writable($dir)) {
            return false;
        }

        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $path = self::getSettingsPath($section, $fieldId);
        if (file_put_contents($path, $json) === false) {
            return false;
        }

        if ($fieldName !== null && $fieldName !== '') {
            $entityIds = $entityIdOverride ?? [self::sectionToEntityId($section)];
            if (!is_array($entityIds)) {
                $entityIds = [$entityIds];
            }
            $byFieldDir = self::getByFieldDir();
            if (!is_dir($byFieldDir)) {
                @mkdir($byFieldDir, 0775, true);
            }
            if (is_writable($byFieldDir)) {
                foreach ($entityIds as $entityId) {
                    $byFieldPath = self::getPathByEntityField($entityId, $fieldName);
                    file_put_contents($byFieldPath, $json);
                }
            }
        }

        return true;
    }

    private static function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function getSettingsPath(string $section, int $fieldId): string
    {
        $safeSection = preg_replace('/[^a-z0-9_]/', '', $section) ?: 'unknown';
        return self::getSettingsDir() . '/' . $safeSection . '_' . $fieldId . '.json';
    }

    private static function getPathByEntityField(string $entityId, string $fieldName): string
    {
        $safeEntity = preg_replace('/[^a-zA-Z0-9_]/', '', $entityId) ?: 'unknown';
        $safeField = preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldName);
        $safeField = $safeField !== '' ? $safeField : 'field';
        return self::getByFieldDir() . '/' . $safeEntity . '_' . $safeField . '.json';
    }

    private static function sectionToEntityId(string $section): string
    {
        return match ($section) {
            'deal' => 'CRM_DEAL',
            'lead' => 'CRM_LEAD',
            'contact' => 'CRM_CONTACT',
            'company' => 'CRM_COMPANY',
            default => 'CRM_' . strtoupper($section),
        };
    }
}
