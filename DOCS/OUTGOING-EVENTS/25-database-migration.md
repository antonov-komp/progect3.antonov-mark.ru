# Миграция хранения событий на базу данных

**Дата создания:** 2026-01-27 (UTC+3, Брест)  
**Версия:** 1.0  
**Статус:** Оценка и план миграции

---

## Назначение

Документ описывает оценку и план миграции хранения событий исходящих вебхуков с файловой системы на базу данных (SQLite или MySQL).

---

## Текущее состояние

### Файловая структура хранения

**События:**
- `logs/{EVENT_TYPE}/raw.json` — полный payload события (маскированный)
- `logs/{EVENT_TYPE}/event.log` — лог-файл с краткой информацией
- `logs/{EVENT_TYPE}/task-details.log` или `comment-details.log` — детали задачи/комментария
- `logs/{EVENT_TYPE}/enriched.json` — обогащенные данные (опционально)

**Очередь:**
- `queue/pending/*.json` — ожидающие обработки
- `queue/processing/*.json` — в процессе обработки
- `queue/done/*.json` — успешно обработанные
- `queue/failed/*.json` — ошибки обработки

**Состояния:**
- `logs/state/{entityId}.json` — состояние сущностей для отслеживания изменений
- `logs/field-changes/field-changes.log` — изменения полей

**Метрики:**
- `logs/activity-first.log` — логи ActivityFirst
- `logs/activity-first-metrics.log` — метрики производительности
- `logs/queue-steps.log` — шаги обработки очереди
- `logs/errors/error-{date}.log` — ошибки

### Структура данных события

**raw.json:**
```json
{
  "requestId": "uuid",
  "eventType": "ONTASKADD",
  "receivedAt": "2026-01-27T12:00:00+03:00",
  "ip": "192.168.1.1",
  "tokenSource": "webhook",
  "eventHandlerId": "123",
  "memberId": "456",
  "payload": { /* маскированный payload */ }
}
```

**event.log:**
```
2026-01-27T12:00:00+03:00 | IP=192.168.1.1 | requestId=uuid | event=ONTASKADD | entityType=task | entityId=123 | eventHandlerId=123 | memberId=456 | tokenSource=webhook
```

**queue/pending/*.json:**
```json
{
  "requestId": "uuid",
  "eventType": "ONTASKADD",
  "entityType": "task",
  "entityId": "123",
  "rawPath": "/path/to/raw.json",
  "createdAt": "2026-01-27T12:00:00+03:00",
  "attempt": 0,
  "priority": "normal",
  "source": "outgoing-webhook",
  "tokenSource": "webhook",
  "eventHandlerId": "123",
  "memberId": "456",
  "payload": { /* маскированный payload */ }
}
```

---

## Оценка перехода на БД

### Преимущества БД

1. **Производительность**
   - Индексы для быстрого поиска
   - Транзакции для атомарности операций
   - Оптимизированные запросы
   - Меньше операций I/O по сравнению с файлами

2. **Масштабируемость**
   - Легко обрабатывать большие объемы данных
   - Партиционирование таблиц по датам
   - Архивация старых данных

3. **Надежность**
   - ACID транзакции
   - Целостность данных
   - Резервное копирование
   - Восстановление после сбоев

4. **Функциональность**
   - Сложные запросы (JOIN, GROUP BY, агрегации)
   - Фильтрация и сортировка
   - Аналитика и отчеты
   - Поиск по содержимому

5. **Управление**
   - Единая точка хранения
   - Упрощенное администрирование
   - Мониторинг и метрики

### Недостатки БД

1. **Сложность**
   - Требуется настройка БД
   - Миграции схемы
   - Управление подключениями

2. **Зависимости**
   - Дополнительное ПО (MySQL) или расширение PHP (SQLite)
   - Резервное копирование БД
   - Мониторинг производительности

3. **Производительность при записи**
   - Может быть медленнее файлов для простой записи
   - Требуется оптимизация индексов

4. **Размер БД**
   - Может расти быстро при большом количестве событий
   - Требуется политика архивации

---

## Сравнение SQLite и MySQL

### SQLite

#### ✅ Преимущества

1. **Простота**
   - Файл БД, не требует отдельного сервера
   - Нет настройки подключений
   - Легко копировать и переносить

2. **Производительность для чтения**
   - Отлично для небольших и средних объемов данных
   - Быстрый доступ к данным

3. **Низкие требования**
   - Встроен в PHP (PDO_SQLite)
   - Не требует дополнительных зависимостей
   - Минимальные ресурсы

4. **Идеально для:**
   - Небольших и средних проектов
   - Локальной разработки
   - Прототипирования

#### ⚠️ Ограничения

1. **Масштабируемость**
   - Один файл БД (может быть узким местом)
   - Нет параллельной записи (WAL режим помогает, но ограничен)
   - Максимальный размер БД: ~140 TB (практически ограничен размером файла)

2. **Производительность записи**
   - Медленнее MySQL при высокой нагрузке
   - Блокировки при записи

3. **Функциональность**
   - Ограниченные типы данных
   - Нет полноценных пользователей и прав доступа
   - Нет репликации

#### 📊 Рекомендации для SQLite

**Подходит если:**
- Объем событий: < 100,000 событий/день
- Пиковая нагрузка: < 50 событий/сек
- Один сервер приложения
- Не требуется сложная аналитика

**Не подходит если:**
- Высокая нагрузка (> 100 событий/сек)
- Требуется репликация
- Множественные серверы приложения
- Сложные аналитические запросы

---

### MySQL

#### ✅ Преимущества

1. **Масштабируемость**
   - Обработка миллионов записей
   - Партиционирование таблиц
   - Репликация для чтения
   - Шардирование

2. **Производительность**
   - Оптимизирован для высокой нагрузки
   - Параллельная запись
   - Кеширование запросов
   - Индексы и оптимизация

3. **Функциональность**
   - Полноценные типы данных (JSON, BLOB, TEXT)
   - Сложные запросы и аналитика
   - Триггеры и хранимые процедуры
   - Пользователи и права доступа

4. **Надежность**
   - ACID транзакции
   - Репликация и кластеризация
   - Резервное копирование
   - Восстановление после сбоев

5. **Инструменты**
   - Мониторинг (MySQL Workbench, phpMyAdmin)
   - Профилирование запросов
   - Оптимизация индексов

#### ⚠️ Недостатки

1. **Сложность**
   - Требуется отдельный сервер БД
   - Настройка подключений
   - Управление пользователями и правами
   - Мониторинг и обслуживание

2. **Ресурсы**
   - Больше потребление памяти
   - Требуется отдельный сервер (или контейнер)
   - Резервное копирование

3. **Зависимости**
   - Требуется MySQL/MariaDB сервер
   - PHP расширение mysqli или PDO_MySQL
   - Настройка окружения

#### 📊 Рекомендации для MySQL

**Подходит если:**
- Объем событий: > 100,000 событий/день
- Пиковая нагрузка: > 50 событий/сек
- Множественные серверы приложения
- Требуется сложная аналитика
- Требуется репликация

**Не подходит если:**
- Небольшой проект (< 10,000 событий/день)
- Нет возможности настройки MySQL сервера
- Прототипирование

---

## План миграции: SQLite

### Этап 1: Подготовка (1-2 дня)

#### 1.1. Создание схемы БД

**Файл:** `outgoing-webhook/database/schema.sqlite.sql`

```sql
-- Таблица событий
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL UNIQUE,
    event_type TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT,
    received_at TEXT NOT NULL,
    ip TEXT NOT NULL,
    token_source TEXT NOT NULL,
    event_handler_id TEXT,
    member_id TEXT,
    payload TEXT NOT NULL, -- JSON
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Индексы для быстрого поиска
CREATE INDEX IF NOT EXISTS idx_events_event_type ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_entity_type ON events(entity_type);
CREATE INDEX IF NOT EXISTS idx_events_entity_id ON events(entity_id);
CREATE INDEX IF NOT EXISTS idx_events_received_at ON events(received_at);
CREATE INDEX IF NOT EXISTS idx_events_request_id ON events(request_id);

-- Таблица деталей задач
CREATE TABLE IF NOT EXISTS task_details (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    task_id TEXT NOT NULL,
    details TEXT NOT NULL, -- JSON
    formatted_details TEXT, -- Форматированный текст для логов
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES events(request_id)
);

CREATE INDEX IF NOT EXISTS idx_task_details_task_id ON task_details(task_id);
CREATE INDEX IF NOT EXISTS idx_task_details_event_type ON task_details(event_type);

-- Таблица деталей комментариев
CREATE TABLE IF NOT EXISTS comment_details (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    task_id TEXT NOT NULL,
    comment_id TEXT NOT NULL,
    details TEXT NOT NULL, -- JSON
    formatted_details TEXT, -- Форматированный текст для логов
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES events(request_id)
);

CREATE INDEX IF NOT EXISTS idx_comment_details_task_id ON comment_details(task_id);
CREATE INDEX IF NOT EXISTS idx_comment_details_comment_id ON comment_details(comment_id);

-- Таблица обогащенных данных
CREATE TABLE IF NOT EXISTS enriched_data (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT,
    enriched_at TEXT NOT NULL,
    source_method TEXT,
    response_time_ms INTEGER,
    data TEXT NOT NULL, -- JSON
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES events(request_id)
);

CREATE INDEX IF NOT EXISTS idx_enriched_event_type ON enriched_data(event_type);
CREATE INDEX IF NOT EXISTS idx_enriched_entity_id ON enriched_data(entity_id);

-- Таблица очереди
CREATE TABLE IF NOT EXISTS queue_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL UNIQUE,
    event_type TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT,
    status TEXT NOT NULL DEFAULT 'pending', -- pending, processing, done, failed
    attempt INTEGER NOT NULL DEFAULT 0,
    priority TEXT NOT NULL DEFAULT 'normal',
    source TEXT NOT NULL DEFAULT 'outgoing-webhook',
    token_source TEXT,
    event_handler_id TEXT,
    member_id TEXT,
    payload TEXT NOT NULL, -- JSON
    error_message TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TEXT,
    FOREIGN KEY (request_id) REFERENCES events(request_id)
);

CREATE INDEX IF NOT EXISTS idx_queue_status ON queue_jobs(status);
CREATE INDEX IF NOT EXISTS idx_queue_created_at ON queue_jobs(created_at);
CREATE INDEX IF NOT EXISTS idx_queue_priority ON queue_jobs(priority);

-- Таблица состояний сущностей
CREATE TABLE IF NOT EXISTS entity_states (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    state TEXT NOT NULL, -- JSON
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entity_type, entity_id)
);

CREATE INDEX IF NOT EXISTS idx_entity_states_entity ON entity_states(entity_type, entity_id);

-- Таблица метрик ActivityFirst
CREATE TABLE IF NOT EXISTS activity_first_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    task_id TEXT NOT NULL,
    logged_at TEXT NOT NULL,
    sync BOOLEAN NOT NULL DEFAULT 0,
    duration_ms INTEGER,
    success BOOLEAN NOT NULL DEFAULT 1,
    files_count INTEGER,
    deals_count INTEGER,
    rate_limit_hit BOOLEAN NOT NULL DEFAULT 0,
    error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_af_metrics_task_id ON activity_first_metrics(task_id);
CREATE INDEX IF NOT EXISTS idx_af_metrics_logged_at ON activity_first_metrics(logged_at);
```

#### 1.2. Создание DatabaseService

**Файл:** `outgoing-webhook/services/Database/DatabaseService.php`

```php
<?php
declare(strict_types=1);

class DatabaseService
{
    private PDO $pdo;
    private string $dbPath;
    private ErrorService $errors;

    public function __construct(string $dbPath, ErrorService $errors)
    {
        $this->dbPath = $dbPath;
        $this->errors = $errors;
        $this->connect();
    }

    private function connect(): void
    {
        try {
            $this->pdo = new PDO("sqlite:{$this->dbPath}", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            // Включаем WAL режим для лучшей производительности
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA synchronous=NORMAL');
            $this->pdo->exec('PRAGMA cache_size=10000');
        } catch (PDOException $e) {
            $this->errors->log('Database connection failed', [
                'error' => $e->getMessage(),
                'path' => $this->dbPath,
            ]);
            throw $e;
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }
}
```

#### 1.3. Создание EventRepository

**Файл:** `outgoing-webhook/services/Database/Repositories/EventRepository.php`

```php
<?php
declare(strict_types=1);

class EventRepository
{
    private DatabaseService $db;
    private ErrorService $errors;

    public function __construct(DatabaseService $db, ErrorService $errors)
    {
        $this->db = $db;
        $this->errors = $errors;
    }

    public function create(array $eventData): ?int
    {
        try {
            $stmt = $this->db->getPdo()->prepare('
                INSERT INTO events (
                    request_id, event_type, entity_type, entity_id,
                    received_at, ip, token_source, event_handler_id,
                    member_id, payload
                ) VALUES (
                    :request_id, :event_type, :entity_type, :entity_id,
                    :received_at, :ip, :token_source, :event_handler_id,
                    :member_id, :payload
                )
            ');

            $stmt->execute([
                ':request_id' => $eventData['requestId'],
                ':event_type' => $eventData['eventType'],
                ':entity_type' => $eventData['entityType'],
                ':entity_id' => $eventData['entityId'],
                ':received_at' => $eventData['receivedAt'],
                ':ip' => $eventData['ip'],
                ':token_source' => $eventData['tokenSource'],
                ':event_handler_id' => $eventData['eventHandlerId'] ?? null,
                ':member_id' => $eventData['memberId'] ?? null,
                ':payload' => json_encode($eventData['payload']),
            ]);

            return (int) $this->db->getPdo()->lastInsertId();
        } catch (PDOException $e) {
            $this->errors->log('Failed to create event', [
                'error' => $e->getMessage(),
                'eventData' => $eventData,
            ]);
            return null;
        }
    }

    public function findByRequestId(string $requestId): ?array
    {
        // ...
    }

    public function findByEventType(string $eventType, int $limit = 100): array
    {
        // ...
    }
}
```

### Этап 2: Рефакторинг EventProcessor (2-3 дня)

#### 2.1. Создание DatabaseEventProcessor

**Файл:** `outgoing-webhook/services/Event/DatabaseEventProcessor.php`

```php
<?php
declare(strict_types=1);

class DatabaseEventProcessor extends EventProcessor
{
    private EventRepository $eventRepository;

    public function __construct(
        RequestService $request,
        EntityIdentityService $identity,
        FilesystemService $filesystem,
        ErrorService $errors,
        LogValueFormatter $formatter,
        EventRepository $eventRepository
    ) {
        parent::__construct($request, $identity, $filesystem, $errors, $formatter);
        $this->eventRepository = $eventRepository;
    }

    protected function logEvent(
        string $eventType,
        string $entityType,
        ?string $entityId,
        string $requestId,
        string $clientIp,
        array $authInfo,
        array $payload
    ): string {
        // Сохранение в БД вместо файла
        $eventData = [
            'requestId' => $requestId,
            'eventType' => $eventType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'receivedAt' => $this->request->now(),
            'ip' => $clientIp,
            'tokenSource' => $authInfo['source'],
            'eventHandlerId' => (string) ($payload['event_handler_id'] ?? 'unknown'),
            'memberId' => (string) ($payload['auth']['member_id'] ?? 'unknown'),
            'payload' => $this->formatter->maskPayload($payload),
        ];

        $eventId = $this->eventRepository->create($eventData);
        
        // Для обратной совместимости возвращаем путь к БД записи
        return "db://events/{$eventId}";
    }
}
```

### Этап 3: Миграция данных (1-2 дня)

#### 3.1. Скрипт миграции

**Файл:** `outgoing-webhook/tools/migrate-to-database.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use OutgoingWebhook\Database\DatabaseService;
use OutgoingWebhook\Database\Repositories\EventRepository;

/**
 * Миграция существующих файлов в БД
 */
function migrateFilesToDatabase(): void
{
    $dbPath = __DIR__ . '/../database/events.db';
    $db = new DatabaseService($dbPath, outgoingWebhookContainer()->get('errors'));
    $eventRepository = new EventRepository($db, outgoingWebhookContainer()->get('errors'));

    $logsDir = __DIR__ . '/../logs';
    $eventTypes = glob($logsDir . '/*', GLOB_ONLYDIR);

    foreach ($eventTypes as $eventDir) {
        $eventType = basename($eventDir);
        $rawFile = $eventDir . '/raw.json';
        
        if (file_exists($rawFile)) {
            $raw = json_decode(file_get_contents($rawFile), true);
            if (is_array($raw)) {
                $eventRepository->create($raw);
            }
        }
    }
}
```

### Этап 4: Тестирование (1-2 дня)

1. Unit-тесты для репозиториев
2. Интеграционные тесты для EventProcessor
3. Тесты производительности
4. Проверка миграции данных

---

## План миграции: MySQL

### Этап 1: Подготовка (2-3 дня)

#### 1.1. Создание схемы БД

**Файл:** `outgoing-webhook/database/schema.mysql.sql`

```sql
-- Таблица событий
CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL UNIQUE,
    event_type VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(50),
    received_at DATETIME(3) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    token_source VARCHAR(20) NOT NULL,
    event_handler_id VARCHAR(50),
    member_id VARCHAR(50),
    payload JSON NOT NULL,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_events_event_type (event_type),
    INDEX idx_events_entity_type (entity_type),
    INDEX idx_events_entity_id (entity_id),
    INDEX idx_events_received_at (received_at),
    INDEX idx_events_request_id (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица деталей задач
CREATE TABLE IF NOT EXISTS task_details (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    task_id VARCHAR(50) NOT NULL,
    details JSON NOT NULL,
    formatted_details TEXT,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_task_details_task_id (task_id),
    INDEX idx_task_details_event_type (event_type),
    INDEX idx_task_details_request_id (request_id),
    FOREIGN KEY (request_id) REFERENCES events(request_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица деталей комментариев
CREATE TABLE IF NOT EXISTS comment_details (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    task_id VARCHAR(50) NOT NULL,
    comment_id VARCHAR(50) NOT NULL,
    details JSON NOT NULL,
    formatted_details TEXT,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_comment_details_task_id (task_id),
    INDEX idx_comment_details_comment_id (comment_id),
    INDEX idx_comment_details_request_id (request_id),
    FOREIGN KEY (request_id) REFERENCES events(request_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица обогащенных данных
CREATE TABLE IF NOT EXISTS enriched_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(50),
    enriched_at DATETIME(3) NOT NULL,
    source_method VARCHAR(100),
    response_time_ms INT UNSIGNED,
    data JSON NOT NULL,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_enriched_event_type (event_type),
    INDEX idx_enriched_entity_id (entity_id),
    INDEX idx_enriched_request_id (request_id),
    FOREIGN KEY (request_id) REFERENCES events(request_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица очереди
CREATE TABLE IF NOT EXISTS queue_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL UNIQUE,
    event_type VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(50),
    status ENUM('pending', 'processing', 'done', 'failed') NOT NULL DEFAULT 'pending',
    attempt INT UNSIGNED NOT NULL DEFAULT 0,
    priority ENUM('low', 'normal', 'high') NOT NULL DEFAULT 'normal',
    source VARCHAR(50) NOT NULL DEFAULT 'outgoing-webhook',
    token_source VARCHAR(20),
    event_handler_id VARCHAR(50),
    member_id VARCHAR(50),
    payload JSON NOT NULL,
    error_message TEXT,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    processed_at TIMESTAMP(3) NULL,
    INDEX idx_queue_status (status),
    INDEX idx_queue_created_at (created_at),
    INDEX idx_queue_priority (priority),
    INDEX idx_queue_request_id (request_id),
    FOREIGN KEY (request_id) REFERENCES events(request_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица состояний сущностей
CREATE TABLE IF NOT EXISTS entity_states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(50) NOT NULL,
    state JSON NOT NULL,
    updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uk_entity_states (entity_type, entity_id),
    INDEX idx_entity_states_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица метрик ActivityFirst
CREATE TABLE IF NOT EXISTS activity_first_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL,
    task_id VARCHAR(50) NOT NULL,
    logged_at DATETIME(3) NOT NULL,
    sync BOOLEAN NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED,
    success BOOLEAN NOT NULL DEFAULT 1,
    files_count INT UNSIGNED,
    deals_count INT UNSIGNED,
    rate_limit_hit BOOLEAN NOT NULL DEFAULT 0,
    error TEXT,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_af_metrics_task_id (task_id),
    INDEX idx_af_metrics_logged_at (logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Партиционирование таблицы events по месяцам (опционально)
-- ALTER TABLE events PARTITION BY RANGE (YEAR(received_at) * 100 + MONTH(received_at)) (
--     PARTITION p202601 VALUES LESS THAN (202602),
--     PARTITION p202602 VALUES LESS THAN (202603),
--     -- ...
-- );
```

#### 1.2. Создание DatabaseService для MySQL

**Файл:** `outgoing-webhook/services/Database/MySqlDatabaseService.php`

```php
<?php
declare(strict_types=1);

class MySqlDatabaseService
{
    private PDO $pdo;
    private ErrorService $errors;

    public function __construct(
        string $host,
        string $database,
        string $username,
        string $password,
        ErrorService $errors
    ) {
        $this->errors = $errors;
        $this->connect($host, $database, $username, $password);
    }

    private function connect(string $host, string $database, string $username, string $password): void
    {
        try {
            $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
        } catch (PDOException $e) {
            $this->errors->log('MySQL connection failed', [
                'error' => $e->getMessage(),
                'host' => $host,
                'database' => $database,
            ]);
            throw $e;
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }
}
```

### Этап 2: Рефакторинг (3-4 дня)

Аналогично SQLite, но с использованием `MySqlDatabaseService`.

### Этап 3: Миграция данных (2-3 дня)

Скрипт миграции с поддержкой больших объемов данных и батч-вставками.

### Этап 4: Оптимизация (2-3 дня)

1. Настройка индексов
2. Партиционирование таблиц
3. Оптимизация запросов
4. Настройка репликации (опционально)

---

## Сравнительная таблица

| Критерий | SQLite | MySQL |
|----------|--------|-------|
| **Сложность внедрения** | Низкая | Средняя |
| **Время внедрения** | 5-8 дней | 10-15 дней |
| **Требования к инфраструктуре** | Нет | Да (отдельный сервер) |
| **Производительность записи** | Средняя | Высокая |
| **Производительность чтения** | Высокая | Очень высокая |
| **Масштабируемость** | Ограниченная | Высокая |
| **Поддержка параллельной записи** | Ограниченная | Полная |
| **Аналитика** | Базовая | Продвинутая |
| **Резервное копирование** | Простое (копирование файла) | Требует настройки |
| **Стоимость** | Низкая | Средняя |

---

## Рекомендации

### Выбор SQLite если:
- ✅ Объем событий < 100,000/день
- ✅ Пиковая нагрузка < 50 событий/сек
- ✅ Один сервер приложения
- ✅ Нужна быстрая миграция
- ✅ Простота важнее масштабируемости

### Выбор MySQL если:
- ✅ Объем событий > 100,000/день
- ✅ Пиковая нагрузка > 50 событий/сек
- ✅ Множественные серверы приложения
- ✅ Требуется сложная аналитика
- ✅ Нужна репликация и высокая доступность

---

## Риски и митигация

### Риск 1: Потеря данных при миграции

**Митигация:**
- Резервное копирование файлов перед миграцией
- Постепенная миграция (старые данные остаются в файлах)
- Валидация данных после миграции

### Риск 2: Снижение производительности

**Митигация:**
- Профилирование до и после миграции
- Оптимизация индексов
- Кеширование частых запросов
- Использование connection pooling

### Риск 3: Увеличение сложности

**Митигация:**
- Детальная документация
- Обучение команды
- Мониторинг и алерты

---

## Связанные задачи

- **TASK-025:** Миграция на SQLite — `DOCS/TASKS/TASK-025-sqlite-migration.md`
- **TASK-026:** Миграция на MySQL — `DOCS/TASKS/TASK-026-mysql-migration.md`

## История изменений

- 2026-01-27 (UTC+3, Брест): Создан документ с оценкой и планом миграции на БД.
- 2026-01-27 (UTC+3, Брест): Созданы детальные задачи TASK-025 (SQLite) и TASK-026 (MySQL).
