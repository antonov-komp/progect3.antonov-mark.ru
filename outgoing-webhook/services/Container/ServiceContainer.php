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

        $this->factories['stateStorage'] = function() {
            $stateDir = $this->basePath . '/logs/state';
            $entityRepo = $this->has('entityStateRepository') ? $this->get('entityStateRepository') : null;
            $changesRepo = $this->has('entityFieldChangesRepository') ? $this->get('entityFieldChangesRepository') : null;
            $dealResolver = $this->has('dealFieldsResolver') ? $this->get('dealFieldsResolver') : null;
            return new StateStorage($stateDir, $entityRepo, $changesRepo, $dealResolver);
        };
        
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
        
        $this->factories['taskDetailsRepository'] = function() {
            $db = $this->get('database');
            if ($db === null) {
                return null;
            }
            return new TaskDetailsRepository($db, $this->get('errors'));
        };
        
        $this->factories['commentDetailsRepository'] = function() {
            $db = $this->get('database');
            if ($db === null) {
                return null;
            }
            return new CommentDetailsRepository($db, $this->get('errors'));
        };

        $this->factories['dealDetailsRepository'] = function() {
            $db = $this->get('database');
            if ($db === null) {
                return null;
            }
            return new DealDetailsRepository($db, $this->get('errors'));
        };
        
        $this->factories['entityStateRepository'] = function() {
            $db = $this->get('database');
            if ($db === null) {
                return null;
            }
            return new EntityStateRepository($db, $this->get('errors'));
        };

        $this->factories['entityFieldChangesRepository'] = function() {
            $db = $this->get('database');
            if ($db === null) {
                return null;
            }
            return new EntityFieldChangesRepository($db, $this->get('errors'));
        };

        $this->factories['activityFirstMetricsRepository'] = function() {
            $db = $this->get('database');
            if ($db === null) {
                return null;
            }
            return new ActivityFirstMetricsRepository($db, $this->get('errors'));
        };

        // Очередь из БД (для process-queue при DATABASE_TYPE=sqlite)
        $this->factories['queue'] = function() {
            $repo = $this->get('queueRepository');
            if ($repo === null) {
                throw new RuntimeException('queue requires queueRepository (database enabled)');
            }
            return new DatabaseQueueService($repo, $this->get('errors'));
        };

        $this->factories['jobState'] = function() {
            $repo = $this->get('queueRepository');
            if ($repo === null) {
                throw new RuntimeException('jobState requires queueRepository (database enabled)');
            }
            $maxAttempts = (int) ($this->get('config')->get('QUEUE_MAX_ATTEMPTS', '3'));
            $processingTimeout = (int) ($this->get('config')->get('QUEUE_PROCESSING_TIMEOUT', '900'));
            return new DatabaseJobStateService($repo, $this->get('errors'), $maxAttempts, $processingTimeout);
        };

        $this->factories['steps'] = fn() => new QueueStepLogger($this->basePath . '/logs/queue-steps.log');

        $this->factories['runner'] = function() {
            $enrichedRepo = $this->has('enrichedDataRepository') ? $this->get('enrichedDataRepository') : null;
            return new QueueRunner(
                $this->get('queue'),
                $this->get('jobState'),
                $this->get('enrichment'),
                $this->get('errors'),
                $this->get('steps'),
                $this->get('taskDetails'),
                $this->get('commentDetails'),
                $enrichedRepo
            );
        };
        
        // Сервисы для работы с задачами
        $this->factories['taskDetails'] = function() {
            $taskDetailsRepo = $this->has('taskDetailsRepository') ? $this->get('taskDetailsRepository') : null;
            $activityFirstRepo = $this->has('activityFirstMetricsRepository') ? $this->get('activityFirstMetricsRepository') : null;
            return new TaskDetailsService(
                $this->get('filesystem'),
                $this->get('request'),
                $this->get('formatter'),
                $taskDetailsRepo,
                $activityFirstRepo
            );
        };
        $this->factories['taskFiles'] = fn() => new TaskFilesService($this->get('errors'));
        $this->factories['dealFiles'] = fn() => new DealFileService(
            $this->get('filesystem'),
            $this->get('request'),
            $this->get('taskFiles'),
            $this->get('errors')
        );

        $this->factories['dicts'] = fn() => new DictCacheService(
            $this->get('rest'),
            $this->get('errors'),
            $this->basePath . '/logs/dicts'
        );

        $this->factories['enrichment'] = function() {
            $dicts = $this->get('dicts');
            $handlers = [
                new DealHandler($dicts),
                new LeadHandler($dicts),
                new SmartProcessHandler($dicts),
                new TaskHandler(),
                new UserHandler(),
                new ProjectHandler(),
                new CrmUserFieldHandler(),
                new ContactHandler(),
                new CompanyHandler(),
            ];
            return new EnrichmentService(
                $this->get('rest'),
                $this->get('errors'),
                $this->get('stateStorage'),
                $handlers
            );
        };

        $this->factories['userResolver'] = fn() => new UserResolver($this->get('dicts'));

        $this->factories['dealFieldsResolver'] = fn() => new DealFieldsResolver(
            $this->get('dicts'),
            $this->get('userResolver')
        );

        $this->factories['dealDetails'] = fn() => new DealDetailsService(
            $this->get('filesystem'),
            $this->get('request'),
            $this->get('formatter'),
            $this->get('dealDetailsRepository'),
            $this->get('dealFieldsResolver')
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
