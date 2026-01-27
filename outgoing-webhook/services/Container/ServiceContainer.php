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
        // basePath должен указывать на корень outgoing-webhook (services/Container -> services -> outgoing-webhook)
        $this->basePath = dirname(__DIR__, 2);
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

        $this->factories['stateStorage'] = fn() => new StateStorage($this->basePath . '/logs/state');
        
        // Database сервисы
        $this->factories['database'] = function() {
            // Проверка наличия расширения PDO_SQLITE
            if (!extension_loaded('pdo_sqlite')) {
                // Логируем, но не бросаем исключение - система будет использовать файлы
                $this->get('errors')->log('PDO_SQLITE extension not loaded for web server', [
                    'message' => 'Database services will not be available. Install php8.3-sqlite3 and restart PHP-FPM.',
                    'available_drivers' => implode(', ', PDO::getAvailableDrivers()),
                ]);
                return null; // Вернем null, чтобы репозитории не создавались
            }
            
            $config = $this->get('config');
            $dbPath = $config->get('DATABASE_PATH', $this->basePath . '/database/events.db');
            $walEnabled = $config->get('DATABASE_WAL_ENABLED', 'true') === 'true';
            
            try {
                // Инициализация схемы БД при первом подключении
                $database = new DatabaseService($dbPath, $this->get('errors'), $walEnabled);
                
                // Если БД не существует, инициализируем схему
                if (!$database->exists()) {
                    $schemaPath = $this->basePath . '/database/schema.sqlite.sql';
                    if (file_exists($schemaPath)) {
                        $database->initializeSchema($schemaPath);
                    }
                }
                
                return $database;
            } catch (Throwable $e) {
                $this->get('errors')->log('Failed to initialize database', [
                    'error' => $e->getMessage(),
                    'path' => $dbPath,
                ]);
                // Не бросаем исключение, возвращаем null для fallback на файлы
                return null;
            }
        };
        
        $this->factories['eventRepository'] = function() {
            $database = $this->get('database');
            if ($database === null) {
                return null; // БД недоступна
            }
            return new EventRepository($database, $this->get('errors'));
        };
        
        $this->factories['queueRepository'] = function() {
            $database = $this->get('database');
            if ($database === null) {
                return null; // БД недоступна
            }
            return new QueueRepository($database, $this->get('errors'));
        };
        
        $this->factories['taskDetailsRepository'] = fn() => new TaskDetailsRepository(
            $this->get('database'),
            $this->get('errors')
        );
        
        $this->factories['commentDetailsRepository'] = fn() => new CommentDetailsRepository(
            $this->get('database'),
            $this->get('errors')
        );
        
        $this->factories['entityStateRepository'] = fn() => new EntityStateRepository(
            $this->get('database'),
            $this->get('errors')
        );
        
        // Сервисы для работы с задачами
        $this->factories['taskDetails'] = function() {
            $taskDetailsRepo = $this->has('taskDetailsRepository') ? $this->get('taskDetailsRepository') : null;
            return new TaskDetailsService(
                $this->get('filesystem'),
                $this->get('request'),
                $this->get('formatter'),
                $taskDetailsRepo
            );
        };
        $this->factories['taskFiles'] = fn() => new TaskFilesService();
        $this->factories['dealFiles'] = fn() => new DealFileService(
            $this->get('filesystem'),
            $this->get('request'),
            $this->get('taskFiles')
        );
        
        // REST сервис (требует Bitrix24Client)
        $this->factories['rest'] = function() {
            // Правильный путь: из outgoing-webhook/services к app (на два уровня вверх)
            // basePath = .../outgoing-webhook; dirname(..., 1) = .../project root
            $appPath = dirname($this->basePath, 1) . '/app';
            require_once $appPath . '/crest.php';
            require_once $appPath . '/Services/Bitrix24Client.php';
            $client = new Bitrix24Client();
            return new RestService(
                $client,
                $this->get('config'),
                $this->get('errors')
            );
        };
        
        // CommentDetailsService (может быть null для rest)
        $this->factories['commentDetails'] = function() {
            $commentDetailsRepo = $this->has('commentDetailsRepository') ? $this->get('commentDetailsRepository') : null;
            return new CommentDetailsService(
                $this->get('rest'),
                $this->get('errors'),
                $this->get('taskDetails'),
                $this->get('taskFiles'),
                $this->get('dealFiles'),
                $this->get('identity'),
                $this->get('request'),
                $this->get('formatter'),
                $this->get('filesystem'),
                $this->get('config'),
                $commentDetailsRepo
            );
        };
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
