<?php
declare(strict_types=1);

/**
 * Service Container для управления зависимостями
 * 
 * Единая точка создания и управления сервисами
 * Устраняет дублирование логики создания сервисов
 */
class ServiceContainer
{
    private array $services = [];
    private array $factories = [];
    private string $basePath;

    public function __construct()
    {
        $this->basePath = dirname(__DIR__, 1);
        $this->registerFactories();
    }

    private function registerFactories(): void
    {
        // Базовые сервисы
        $this->factories['config'] = fn() => new ConfigService();
        $this->factories['filesystem'] = fn() => new FilesystemService();
        $this->factories['request'] = fn() => new RequestService($this->get('config'));
        $this->factories['access'] = fn() => new AccessService($this->get('config'));
        $this->factories['formatter'] = fn() => new LogValueFormatter();
        $this->factories['errors'] = fn() => new ErrorService(
            $this->get('filesystem'),
            $this->get('request')
        );
        $this->factories['identity'] = fn() => new EntityIdentityService($this->get('request'));
        
        // Сервисы для работы с задачами
        $this->factories['taskDetails'] = fn() => new TaskDetailsService(
            $this->get('filesystem'),
            $this->get('request'),
            $this->get('formatter')
        );
        $this->factories['taskFiles'] = fn() => new TaskFilesService();
        $this->factories['dealFiles'] = fn() => new DealFileService(
            $this->get('filesystem'),
            $this->get('request'),
            $this->get('taskFiles')
        );
        
        // REST сервис (требует Bitrix24Client)
        $this->factories['rest'] = function() {
            require_once $this->basePath . '/../app/crest.php';
            require_once $this->basePath . '/../app/Services/Bitrix24Client.php';
            $client = new Bitrix24Client();
            return new RestService(
                $client,
                $this->get('config'),
                $this->get('errors')
            );
        };
        
        // CommentDetailsService (может быть null для rest)
        $this->factories['commentDetails'] = fn() => new CommentDetailsService(
            $this->get('rest'),
            $this->get('errors'),
            $this->get('taskDetails'),
            $this->get('taskFiles'),
            $this->get('dealFiles'),
            $this->get('identity'),
            $this->get('request'),
            $this->get('formatter'),
            $this->get('filesystem'),
            $this->get('config')
        );
    }

    public function get(string $key)
    {
        if (isset($this->services[$key])) {
            return $this->services[$key];
        }

        if (!isset($this->factories[$key])) {
            throw new InvalidArgumentException("Unknown service: {$key}");
        }

        $this->services[$key] = $this->factories[$key]();
        return $this->services[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->factories[$key]);
    }

    public function set(string $key, callable $factory): void
    {
        $this->factories[$key] = $factory;
        unset($this->services[$key]);
    }
}
