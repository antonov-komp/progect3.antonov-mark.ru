# TASK-026: Миграция хранения событий на MySQL

**Дата создания:** 2026-01-27 (UTC+3, Брест)  
**Статус:** Новая  
**Приоритет:** Средний  
**Исполнитель:** Laravel Программист / Backend PHP Developer

---

## Описание

Миграция хранения событий исходящих вебхуков с файловой системы на MySQL базу данных для улучшения производительности, масштабируемости и функциональности при больших объемах данных.

## Контекст

Текущая система хранит события в файлах. Переход на MySQL позволит:
- Обрабатывать миллионы записей
- Использовать партиционирование таблиц
- Выполнять сложные аналитические запросы
- Поддерживать репликацию для высокой доступности
- Масштабироваться на множественные серверы

## Модули и компоненты

### Новые файлы

1. **Database Service**
   - `outgoing-webhook/services/Database/MySqlDatabaseService.php` — сервис для работы с MySQL
   - `outgoing-webhook/services/Database/Repositories/EventRepository.php` — репозиторий событий
   - `outgoing-webhook/services/Database/Repositories/TaskDetailsRepository.php` — репозиторий деталей задач
   - `outgoing-webhook/services/Database/Repositories/CommentDetailsRepository.php` — репозиторий деталей комментариев
   - `outgoing-webhook/services/Database/Repositories/QueueRepository.php` — репозиторий очереди
   - `outgoing-webhook/services/Database/Repositories/EntityStateRepository.php` — репозиторий состояний

2. **Схема БД**
   - `outgoing-webhook/database/schema.mysql.sql` — SQL схема для MySQL
   - `outgoing-webhook/database/migrations/` — миграции схемы
   - `outgoing-webhook/database/partitions/` — скрипты партиционирования

3. **Рефакторинг существующих сервисов**
   - `outgoing-webhook/services/Event/DatabaseEventProcessor.php` — процессор событий с БД
   - `outgoing-webhook/services/Queue/DatabaseQueueService.php` — сервис очереди с БД

4. **Миграция данных**
   - `outgoing-webhook/tools/migrate-to-mysql.php` — скрипт миграции файлов в БД
   - Поддержка батч-вставок для больших объемов

5. **Оптимизация**
   - `outgoing-webhook/database/optimization/` — скрипты оптимизации индексов
   - Настройка партиционирования

6. **Конфигурация**
   - Обновление `ServiceContainer` для регистрации новых сервисов
   - Добавление настроек БД в `config.local.php`

## Зависимости

- MySQL 5.7+ или MariaDB 10.3+
- PHP PDO с поддержкой MySQL (PDO_MySQL)
- Доступ к MySQL серверу
- Права на создание БД и таблиц

## Ступенчатые подзадачи

### Этап 1: Подготовка инфраструктуры (2-3 дня)

1. **Настройка MySQL сервера**
   - Установка MySQL/MariaDB (если не установлен)
   - Создание БД для событий
   - Создание пользователя с необходимыми правами
   - Настройка кодировки (utf8mb4)

2. **Создание схемы БД**
   ```bash
   mysql -u user -p database_name < database/schema.mysql.sql
   ```
   - Таблица `events` — основные события (JSON поле для payload)
   - Таблица `task_details` — детали задач
   - Таблица `comment_details` — детали комментариев
   - Таблица `enriched_data` — обогащенные данные
   - Таблица `queue_jobs` — очередь обработки (ENUM для статусов)
   - Таблица `entity_states` — состояния сущностей
   - Таблица `activity_first_metrics` — метрики ActivityFirst
   - Индексы для всех таблиц
   - Foreign keys для целостности данных

3. **Настройка партиционирования (опционально)**
   - Партиционирование таблицы `events` по месяцам
   - Скрипты для автоматического создания партиций
   - Политика архивации старых партиций

### Этап 2: Создание Database Service (1-2 дня)

4. **Создание MySqlDatabaseService**
   - Подключение к MySQL через PDO
   - Настройка charset utf8mb4
   - Connection pooling (опционально)
   - Методы для транзакций
   - Обработка ошибок подключения
   - Retry логика при временных сбоях

5. **Создание базовых репозиториев**
   - `EventRepository` — CRUD операции для событий
   - Использование prepared statements
   - Поддержка JSON полей MySQL
   - Батч-вставки для производительности

### Этап 3: Рефакторинг EventProcessor (2-3 дня)

6. **Создание DatabaseEventProcessor**
   - Наследование от `EventProcessor`
   - Переопределение метода `logEvent()` для записи в БД
   - Использование JSON полей MySQL для payload
   - Сохранение обратной совместимости

7. **Интеграция с ServiceContainer**
   - Регистрация `MySqlDatabaseService` в контейнере
   - Регистрация репозиториев
   - Настройка зависимостей из конфигурации

8. **Обновление index.php**
   - Использование `DatabaseEventProcessor` вместо `EventProcessor`
   - Обработка ошибок БД

### Этап 4: Рефакторинг QueueService (2-3 дня)

9. **Создание DatabaseQueueService**
   - Реализация методов `listPending()`, `count()`
   - Использование ENUM для статусов
   - Атомарные операции через транзакции
   - SELECT FOR UPDATE для блокировки заданий

10. **Обновление QueueRunner**
    - Использование `DatabaseQueueService`
    - Обновление статусов заданий в БД
    - Обработка зависших заданий

### Этап 5: Рефакторинг TaskDetailsService и CommentDetailsService (2-3 дня)

11. **Создание репозиториев для деталей**
    - `TaskDetailsRepository` — сохранение деталей задач
    - `CommentDetailsRepository` — сохранение деталей комментариев
    - Использование JSON полей для деталей
    - TEXT поле для форматированного текста

12. **Обновление сервисов**
    - Использование репозиториев вместо файлов
    - Сохранение форматированного текста для логов

### Этап 6: Миграция данных (2-3 дня)

13. **Создание скрипта миграции**
    - Чтение существующих файлов из `logs/`
    - Парсинг JSON файлов
    - Батч-вставки в БД (по 1000 записей)
    - Прогресс-бар для отслеживания
    - Валидация данных после миграции

14. **Оптимизация миграции**
    - Отключение индексов во время миграции
    - Включение индексов после миграции
    - Проверка целостности данных

### Этап 7: Оптимизация и настройка (2-3 дня)

15. **Оптимизация индексов**
    - Анализ запросов (EXPLAIN)
    - Создание составных индексов
    - Удаление неиспользуемых индексов

16. **Настройка партиционирования**
    - Создание партиций для текущего месяца
    - Скрипт для автоматического создания партиций
    - Политика архивации старых партиций

17. **Настройка репликации (опционально)**
    - Настройка master-slave репликации
    - Чтение с реплик для аналитики
    - Мониторинг lag репликации

### Этап 8: Тестирование (2-3 дня)

18. **Unit-тесты**
    - Тесты для репозиториев
    - Тесты для MySqlDatabaseService
    - Тесты для DatabaseEventProcessor

19. **Интеграционные тесты**
    - Тесты полного цикла обработки события
    - Тесты обработки очереди
    - Тесты партиционирования

20. **Нагрузочное тестирование**
    - Тестирование при высокой нагрузке (> 100 событий/сек)
    - Проверка производительности запросов
    - Оптимизация узких мест

## Технические требования

### MySQL специфика

- **Версия:** MySQL 5.7+ или MariaDB 10.3+
- **Расширение PHP:** PDO_MySQL или mysqli
- **Кодировка:** utf8mb4 для поддержки emoji
- **Движок:** InnoDB для транзакций и внешних ключей
- **JSON поля:** Использование типа JSON для payload и деталей

### Производительность

- **Целевая нагрузка:** > 50 событий/сек (до 1000+)
- **Объем данных:** > 100,000 событий/день (миллионы)
- **Размер БД:** Рост ~10-50 MB/день (зависит от размера payload)
- **Партиционирование:** По месяцам для оптимизации запросов

### Масштабируемость

- Поддержка множественных серверов приложения
- Репликация для чтения (опционально)
- Шардирование (для очень больших объемов)

## API-методы (репозитории)

### EventRepository

```php
public function create(array $eventData): ?int
public function createBatch(array $eventsData): int // Батч-вставка
public function findByRequestId(string $requestId): ?array
public function findByEventType(string $eventType, int $limit = 100, int $offset = 0): array
public function findByEntityId(string $entityId, string $entityType): array
public function countByEventType(string $eventType): int
public function findByDateRange(string $startDate, string $endDate): array
```

### QueueRepository

```php
public function createJob(array $jobData): ?int
public function listPending(int $limit): array
public function updateStatus(int $jobId, string $status, ?string $errorMessage = null): bool
public function countByStatus(string $status): int
public function lockJob(int $jobId): bool // SELECT FOR UPDATE
public function unlockStuckJobs(int $timeoutSeconds = 300): int
```

## Критерии приёмки

- [ ] MySQL сервер настроен и доступен
- [ ] Схема БД создана и протестирована
- [ ] MySqlDatabaseService работает корректно
- [ ] Все репозитории реализованы и протестированы
- [ ] EventProcessor использует БД вместо файлов
- [ ] QueueService использует БД вместо файлов
- [ ] Миграция данных выполнена успешно
- [ ] Партиционирование настроено (если требуется)
- [ ] Индексы оптимизированы
- [ ] Все существующие тесты проходят
- [ ] Производительность лучше файловой системы
- [ ] Нагрузочное тестирование пройдено
- [ ] Документация обновлена

## Тестирование

1. **Создание тестовой БД**
   ```bash
   mysql -u user -p < database/schema.mysql.sql
   ```

2. **Тестирование записи событий**
   - Создание события через API
   - Проверка записи в БД
   - Проверка JSON полей

3. **Тестирование чтения**
   - Поиск по requestId
   - Поиск по eventType с пагинацией
   - Поиск по entityId
   - Поиск по диапазону дат

4. **Тестирование производительности**
   - Нагрузочное тестирование (> 100 событий/сек)
   - Тестирование батч-вставок
   - Тестирование партиционирования

5. **Тестирование репликации** (если настроена)
   - Проверка lag репликации
   - Тестирование чтения с реплик

## Конфигурация

### config.local.php

```php
<?php
return [
    // MySQL настройки
    'DATABASE_TYPE' => 'mysql',
    'DATABASE_HOST' => 'localhost',
    'DATABASE_PORT' => 3306,
    'DATABASE_NAME' => 'outgoing_webhook',
    'DATABASE_USER' => 'webhook_user',
    'DATABASE_PASSWORD' => 'secure_password',
    'DATABASE_CHARSET' => 'utf8mb4',
    
    // Партиционирование
    'DATABASE_PARTITIONING_ENABLED' => true,
    'DATABASE_PARTITION_MONTHS_AHEAD' => 3,
    
    // Репликация (опционально)
    'DATABASE_REPLICA_ENABLED' => false,
    'DATABASE_REPLICA_HOST' => 'replica.example.com',
];
```

### ServiceContainer

```php
$this->factories['database'] = fn() => new MySqlDatabaseService(
    $this->get('config')->get('DATABASE_HOST', 'localhost'),
    $this->get('config')->get('DATABASE_NAME', 'outgoing_webhook'),
    $this->get('config')->get('DATABASE_USER'),
    $this->get('config')->get('DATABASE_PASSWORD'),
    $this->get('errors')
);

$this->factories['eventRepository'] = fn() => new EventRepository(
    $this->get('database'),
    $this->get('errors')
);
```

## Риски и митигация

### Риск 1: Потеря данных при миграции

**Митигация:**
- Резервное копирование файлов перед миграцией
- Резервное копирование БД после миграции
- Постепенная миграция (старые данные остаются в файлах)
- Валидация данных после миграции
- Транзакции для атомарности операций

### Риск 2: Снижение производительности

**Митигация:**
- Оптимизация индексов
- Батч-вставки при миграции
- Партиционирование таблиц
- Профилирование запросов (EXPLAIN)
- Connection pooling

### Риск 3: Перегрузка БД при высокой нагрузке

**Митигация:**
- Rate limiting на уровне приложения
- Очередь для записи в БД
- Асинхронная запись через очереди
- Мониторинг нагрузки БД
- Масштабирование (репликация, шардирование)

### Риск 4: Проблемы с подключением

**Митигация:**
- Retry логика при временных сбоях
- Connection pooling
- Таймауты для операций
- Fallback на файловую систему (опционально)
- Мониторинг состояния БД

## История правок

- 2026-01-27 (UTC+3, Брест): Создана задача для миграции на MySQL.
