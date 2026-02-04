# TASK-025: Миграция хранения событий на SQLite

**Дата создания:** 2026-01-27 (UTC+3, Брест)  
**Статус:** Новая  
**Приоритет:** Средний  
**Исполнитель:** Laravel Программист / Backend PHP Developer

---

## Описание

Миграция хранения событий исходящих вебхуков с файловой системы на SQLite базу данных для улучшения производительности, масштабируемости и функциональности.

## Контекст

Текущая система хранит события в файлах:
- `logs/{EVENT_TYPE}/raw.json` — полный payload
- `logs/{EVENT_TYPE}/event.log` — лог-файл
- `queue/pending/*.json` — очередь обработки

Переход на SQLite позволит:
- Использовать индексы для быстрого поиска
- Выполнять сложные запросы
- Улучшить производительность при больших объемах данных
- Упростить аналитику

## Модули и компоненты

### Новые файлы

1. **Database Service**
   - `outgoing-webhook/services/Database/DatabaseService.php` — сервис для работы с SQLite
   - `outgoing-webhook/services/Database/Repositories/EventRepository.php` — репозиторий событий
   - `outgoing-webhook/services/Database/Repositories/TaskDetailsRepository.php` — репозиторий деталей задач
   - `outgoing-webhook/services/Database/Repositories/CommentDetailsRepository.php` — репозиторий деталей комментариев
   - `outgoing-webhook/services/Database/Repositories/QueueRepository.php` — репозиторий очереди
   - `outgoing-webhook/services/Database/Repositories/EntityStateRepository.php` — репозиторий состояний

2. **Схема БД**
   - `outgoing-webhook/database/schema.sqlite.sql` — SQL схема для SQLite
   - `outgoing-webhook/database/migrations/` — миграции схемы

3. **Рефакторинг существующих сервисов**
   - `outgoing-webhook/services/Event/DatabaseEventProcessor.php` — процессор событий с БД
   - `outgoing-webhook/services/Queue/DatabaseQueueService.php` — сервис очереди с БД

4. **Миграция данных**
   - `outgoing-webhook/tools/migrate-to-sqlite.php` — скрипт миграции файлов в БД

5. **Конфигурация**
   - Обновление `ServiceContainer` для регистрации новых сервисов
   - Добавление настроек БД в `config.local.php`

## Зависимости

- PHP PDO с поддержкой SQLite (обычно встроен)
- Существующие сервисы: `EventProcessor`, `QueueService`, `FilesystemService`

## Ступенчатые подзадачи

### Этап 1: Подготовка (1-2 дня)

1. **Создание схемы БД**
   ```bash
   # Создать файл схемы
   touch outgoing-webhook/database/schema.sqlite.sql
   ```
   - Таблица `events` — основные события
   - Таблица `task_details` — детали задач
   - Таблица `comment_details` — детали комментариев
   - Таблица `enriched_data` — обогащенные данные
   - Таблица `queue_jobs` — очередь обработки
   - Таблица `entity_states` — состояния сущностей
   - Таблица `activity_first_metrics` — метрики ActivityFirst
   - Индексы для всех таблиц

2. **Создание DatabaseService**
   - Подключение к SQLite через PDO
   - Настройка WAL режима для лучшей производительности
   - Методы для транзакций (beginTransaction, commit, rollback)
   - Обработка ошибок подключения

3. **Создание базовых репозиториев**
   - `EventRepository` — CRUD операции для событий
   - Базовые методы: `create()`, `findByRequestId()`, `findByEventType()`
   - Использование prepared statements

### Этап 2: Рефакторинг EventProcessor (2-3 дня)

4. **Создание DatabaseEventProcessor**
   - Наследование от `EventProcessor`
   - Переопределение метода `logEvent()` для записи в БД
   - Сохранение обратной совместимости (возврат пути к БД записи)

5. **Интеграция с ServiceContainer**
   - Регистрация `DatabaseService` в контейнере
   - Регистрация репозиториев
   - Настройка зависимостей

6. **Обновление index.php**
   - Использование `DatabaseEventProcessor` вместо `EventProcessor`
   - Сохранение fallback на файловую систему (опционально)

### Этап 3: Рефакторинг QueueService (2-3 дня)

7. **Создание DatabaseQueueService**
   - Реализация методов `listPending()`, `count()`
   - Методы для изменения статуса заданий
   - Атомарные операции через транзакции

8. **Обновление QueueRunner**
   - Использование `DatabaseQueueService`
   - Обновление статусов заданий в БД

### Этап 4: Рефакторинг TaskDetailsService и CommentDetailsService (2-3 дня)

9. **Создание репозиториев для деталей**
   - `TaskDetailsRepository` — сохранение деталей задач
   - `CommentDetailsRepository` — сохранение деталей комментариев
   - Методы для записи и чтения

10. **Обновление сервисов**
    - Использование репозиториев вместо файлов
    - Сохранение форматированного текста для логов

### Этап 5: Миграция данных (1-2 дня)

11. **Создание скрипта миграции**
    - Чтение существующих файлов из `logs/`
    - Парсинг JSON файлов
    - Вставка данных в БД батчами
    - Валидация данных после миграции

12. **Тестирование миграции**
    - Проверка целостности данных
    - Сравнение количества записей
    - Проверка корректности данных

### Этап 6: Тестирование и оптимизация (1-2 дня)

13. **Unit-тесты**
    - Тесты для репозиториев
    - Тесты для DatabaseService
    - Тесты для DatabaseEventProcessor

14. **Интеграционные тесты**
    - Тесты полного цикла обработки события
    - Тесты обработки очереди

15. **Оптимизация**
    - Анализ производительности запросов
    - Оптимизация индексов
    - Настройка WAL режима

## Технические требования

### SQLite специфика

- **Версия:** SQLite 3.x (встроен в PHP)
- **Расширение PHP:** PDO_SQLITE
- **WAL режим:** Включен для лучшей производительности
- **Размер БД:** Практически неограничен (до 140 TB)
- **Параллельная запись:** Ограничена (WAL помогает)

### Производительность

- **Целевая нагрузка:** < 50 событий/сек
- **Объем данных:** < 100,000 событий/день
- **Размер БД:** Рост ~1-5 MB/день (зависит от размера payload)

### Ограничения SQLite

- Один файл БД (может быть узким местом)
- Ограниченная параллельная запись
- Нет репликации

## API-методы (репозитории)

### EventRepository

```php
public function create(array $eventData): ?int
public function findByRequestId(string $requestId): ?array
public function findByEventType(string $eventType, int $limit = 100): array
public function findByEntityId(string $entityId, string $entityType): array
public function countByEventType(string $eventType): int
```

### QueueRepository

```php
public function createJob(array $jobData): ?int
public function listPending(int $limit): array
public function updateStatus(int $jobId, string $status): bool
public function countByStatus(string $status): int
```

## Критерии приёмки

- [ ] Схема БД создана и протестирована
- [ ] DatabaseService работает корректно
- [ ] Все репозитории реализованы и протестированы
- [ ] EventProcessor использует БД вместо файлов
- [ ] QueueService использует БД вместо файлов
- [ ] Миграция данных выполнена успешно
- [ ] Все существующие тесты проходят
- [ ] Производительность не хуже файловой системы
- [ ] Документация обновлена

## Тестирование

1. **Создание тестовой БД**
   ```bash
   sqlite3 test.db < database/schema.sqlite.sql
   ```

2. **Тестирование записи событий**
   - Создание события через API
   - Проверка записи в БД
   - Проверка индексов

3. **Тестирование чтения**
   - Поиск по requestId
   - Поиск по eventType
   - Поиск по entityId

4. **Тестирование производительности**
   - Нагрузочное тестирование
   - Сравнение с файловой системой

## Конфигурация

### config.local.php

```php
<?php
return [
    // SQLite настройки
    'DATABASE_TYPE' => 'sqlite',
    'DATABASE_PATH' => __DIR__ . '/../database/events.db',
    
    // Fallback на файлы (опционально)
    'DATABASE_FALLBACK_TO_FILES' => false,
];
```

### ServiceContainer

```php
$this->factories['database'] = fn() => new DatabaseService(
    $this->get('config')->get('DATABASE_PATH', __DIR__ . '/../database/events.db'),
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
- Постепенная миграция (старые данные остаются в файлах)
- Валидация данных после миграции

### Риск 2: Снижение производительности

**Митигация:**
- Использование WAL режима
- Оптимизация индексов
- Батч-вставки при миграции
- Профилирование запросов

### Риск 3: Блокировки при параллельной записи

**Митигация:**
- WAL режим уменьшает блокировки
- Таймауты для операций записи
- Retry логика при блокировках

## История правок

- 2026-01-27 (UTC+3, Брест): Создана задача для миграции на SQLite.
