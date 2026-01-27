<?php
declare(strict_types=1);

/**
 * Сервис для работы с конфигурацией Activity
 * 
 * Ответственность:
 * - Загрузка и валидация конфигурации Activity
 * - Нормализация ключевых слов (обработка опечаток)
 * - Получение поля сделки для типа Activity
 * - Проверка наличия ключевых слов в сообщении
 */
class ActivityConfigService
{
    private array $config;
    private string $configPath;
    
    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
        $this->config = $this->loadConfig($configPath);
    }
    
    /**
     * Загрузка конфигурации из файла
     * 
     * @param string $path Путь к файлу конфигурации
     * @return array Конфигурация Activity
     */
    public function loadConfig(string $path): array
    {
        if (!file_exists($path)) {
            return $this->getDefaultConfig();
        }
        
        $loaded = require $path;
        return is_array($loaded) ? $loaded : $this->getDefaultConfig();
    }
    
    /**
     * Получение всех типов Activity
     * 
     * @return array Массив идентификаторов типов Activity
     */
    public function getActivityTypes(): array
    {
        return array_keys($this->config['activities'] ?? []);
    }
    
    /**
     * Получение конфигурации типа Activity
     * 
     * @param string $type Идентификатор типа Activity
     * @return array|null Конфигурация типа или null если не найден
     */
    public function getActivityConfig(string $type): ?array
    {
        return $this->config['activities'][$type] ?? null;
    }
    
    /**
     * Получение поля сделки для типа Activity
     * 
     * @param string $type Идентификатор типа Activity
     * @return string Поле сделки или пустая строка если не найдено
     */
    public function getDealField(string $type): string
    {
        $activityConfig = $this->getActivityConfig($type);
        return $activityConfig['dealField'] ?? '';
    }
    
    /**
     * Получение общих настроек
     * 
     * @return array Общие настройки
     */
    public function getCommonConfig(): array
    {
        return $this->config['common'] ?? [];
    }
    
    /**
     * Нормализация ключевого слова для обработки опечаток
     * 
     * Логика:
     * - Приведение к нижнему регистру
     * - Удаление лишних пробелов
     * - Исправление опечаток (например, "согласованый" → "согласованный")
     * 
     * @param string $keyword Ключевое слово для нормализации
     * @return string Нормализованное ключевое слово
     */
    public function normalizeKeyword(string $keyword): string
    {
        // Нормализация опечаток
        $normalized = mb_strtolower(trim($keyword));
        
        // Исправление "согласованый" → "согласованный" (две "н")
        $normalized = preg_replace('/согласованый/', 'согласованный', $normalized);
        
        // Удаление лишних пробелов
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        return trim($normalized);
    }
    
    /**
     * Проверка наличия ключевых слов типа Activity в сообщении
     * 
     * @param string $message Сообщение для проверки
     * @param string $activityType Тип Activity
     * @return bool true если найдено ключевое слово
     */
    public function messageHasActivityKeyword(string $message, string $activityType): bool
    {
        $config = $this->getActivityConfig($activityType);
        if ($config === null) {
            return false;
        }
        
        $keywords = $config['keywords'] ?? [];
        $normalize = $config['normalizeKeywords'] ?? false;
        
        // Нормализация сообщения
        $messageNormalized = $normalize ? $this->normalizeKeyword($message) : mb_strtolower($message);
        
        // Проверка каждого ключевого слова
        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || $keyword === '') {
                continue;
            }
            
            $keywordNormalized = $normalize ? $this->normalizeKeyword($keyword) : mb_strtolower($keyword);
            
            // Поиск ключевого слова в сообщении (регистронезависимый)
            if (mb_stripos($messageNormalized, $keywordNormalized) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Получение конфигурации по умолчанию
     * 
     * @return array Конфигурация по умолчанию
     */
    private function getDefaultConfig(): array
    {
        return [
            'common' => [
                'projectId' => null,
                'crmDealPrefix' => 'D_',
            ],
            'activities' => [],
        ];
    }
}
