# Структура файлов и директорий модуля outgoing-webhook

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает полную структуру модуля `outgoing-webhook`: назначение каждой директории и файла, формат файлов, правила именования.

---

## Корневая структура

```
outgoing-webhook/
├── activity/              # Логика ActivityFirst
├── bootstrap.php          # Shim-функции и точка входа
├── index.php              # Эндпоинт приёма событий
├── services/              # Сервисный слой
├── tests/                 # Тесты
├── tools/                 # Инструменты и утилиты
├── queue/                 # Файловая очередь
├── logs/                  # Логи и данные
├── state/                 # Состояния и снимки
└── config.local.php       # Локальная конфигурация (опционально)
```

---

## activity/

### Назначение

Логика ActivityFirst — проверка условий для создания активности.

### Структура

```
activity/
└── first/
    └── conditions.php     # Условия ActivityFirst
```

### conditions.php

**Формат:**
```php
<?php
return [
    'projectId' => '15',
    'crmDealPrefix' => 'D_',
    'keywords' => ['обложка', 'Обложка', 'ОБЛОЖКА'],
];
```

**Использование:** загружается через `TaskDetailsService::loadActivityFirstConditions()`

---

## bootstrap.php

### Назначение

Shim-функции для обратной совместимости. Делегирует вызовы сервисам.

**Подробности:** `bootstrap/README.md`

---

## index.php

### Назначение

Эндпоинт приёма событий от Bitrix24.

**Публичная точка:** `public/outgoing-webhook/index.php`

**Метод:** `POST`

**Функциональность:**
- Валидация запроса (метод, размер, токен, IP)
- Нормализация eventType
- Извлечение entityId
- Запись логов
- Создание задания в очереди
- Быстрая обработка задач и комментариев
- Синхронная обработка ActivityFirst

---

## services/

### Назначение

Сервисный слой модуля. Вся бизнес-логика вынесена в сервисы.

**Подробности:** `05-services-architecture.md`

### Структура

```
services/
├── bootstrap.php                    # Автозагрузка сервисов
├── Config/
│   └── ConfigService.php
├── Core/
│   └── FilesystemService.php
├── Http/
│   └── RequestService.php
├── Security/
│   └── AccessService.php
├── Identity/
│   └── EntityIdentityService.php
├── Logging/
│   ├── ErrorService.php
│   ├── LogValueFormatter.php
│   └── QueueStepLogger.php
├── Rest/
│   └── RestService.php
├── Dicts/
│   └── DictCacheService.php
├── Enrichment/
│   ├── EnrichmentService.php
│   ├── StateStorage.php
│   ├── ValueComparator.php
│   └── EntityHandlers/
│       ├── EntityHandlerInterface.php
│       ├── DefaultEntityHandler.php
│       ├── DealHandler.php
│       ├── LeadHandler.php
│       ├── ContactHandler.php
│       ├── CompanyHandler.php
│       ├── SmartProcessHandler.php
│       ├── TaskHandler.php
│       ├── UserHandler.php
│       ├── ProjectHandler.php
│       └── CrmUserFieldHandler.php
├── Queue/
│   ├── QueueService.php
│   ├── QueueJob.php
│   ├── QueueRunner.php
│   └── JobStateService.php
├── Task/
│   ├── TaskDetailsService.php
│   ├── TaskFilesService.php
│   └── CommentDetailsService.php
└── Crm/
    └── DealFileService.php
```

---

## tests/

### Назначение

Тесты модуля.

### Структура

```
tests/
└── services-smoke.php     # Дымовые тесты сервисов
```

**Подробности:** `21-testing.md`

---

## tools/

### Назначение

Инструменты и утилиты для работы с модулем.

### Структура

```
tools/
├── allowed-events.php         # Анализ доступных событий
├── test-token-events.php      # Тестирование событий
├── process-queue.php           # HTTP-обработчик очереди
└── process-queue-cli.php       # CLI-обработчик очереди
```

**Подробности:**
- `14-tools-allowed-events.md`
- `15-tools-test-events.md`
- `16-tools-queue-processors.md`

---

## queue/

### Назначение

Файловая очередь обработки событий.

### Структура

```
queue/
├── pending/        # Задания, ожидающие обработки
├── processing/     # Задания, находящиеся в обработке
├── done/           # Успешно обработанные задания
└── failed/         # Задания, завершившиеся с ошибкой
```

**Формат имени файла:** `{YYYYMMDD}_{HHMMSS}_{EVENT_TYPE}_{ENTITY_ID}.json`

**Подробности:** `10-queue-detailed.md`

---

## logs/

### Назначение

Логи и данные событий.

### Структура

```
logs/
├── {EVENT_TYPE}/           # Логи для каждого типа события
│   ├── raw.json            # Сырой payload (маскированный)
│   ├── event.log           # Строковый журнал событий
│   ├── enriched.json       # Обогащённые данные
│   ├── task-details.log    # Детали задач (для ONTASK*)
│   └── comment-details.log # Детали комментариев (для ONTASKCOMMENTADD)
├── errors/
│   └── error-YYYYMMDD.log  # Логи ошибок (line-based JSON)
├── allowed-events/
│   ├── allowed-events.json # Сгруппированные методы
│   └── raw-methods.json    # Полный список методов
├── dicts/
│   └── *.json              # Кеш справочников
├── state/
│   └── {entityType}_{entityId}.json  # Снимки состояний
├── field-changes/
│   ├── {entityType}_{entityId}_{timestamp}.json  # Изменения полей
│   └── field-changes.log    # Лог изменений (line-based JSON)
├── queue-steps.log          # Логирование шагов очереди
├── activity-first.log       # Логирование ActivityFirst
└── activity-first-metrics.log  # Метрики ActivityFirst
```

### Описание директорий

#### logs/{EVENT_TYPE}/

**Назначение:** Логи для конкретного типа события

**Файлы:**
- `raw.json` — последний принятый payload (маскированный)
- `event.log` — строковый журнал событий
- `enriched.json` — обогащённые данные (после обработки очереди)
- `task-details.log` — детали задач (только для `ONTASK*`)
- `comment-details.log` — детали комментариев (только для `ONTASKCOMMENTADD`)

**Примеры:**
- `logs/ONTASKADD/`
- `logs/ONCRMDEALUPDATE/`
- `logs/ONTASKCOMMENTADD/`

#### logs/errors/

**Назначение:** Логи ошибок

**Формат имени файла:** `error-YYYYMMDD.log`

**Формат записи:** line-based JSON

#### logs/allowed-events/

**Назначение:** Результаты анализа доступных событий

**Файлы:**
- `allowed-events.json` — сгруппированные методы по категориям
- `raw-methods.json` — полный список методов

#### logs/dicts/

**Назначение:** Кеш справочников

**Формат имени файла:** `{name}.json`

**Примеры:**
- `deal_categories.json`
- `deal_stages.json`
- `lead_stages.json`

**TTL:** 24 часа (86400 секунд)

#### logs/state/

**Назначение:** Снимки состояний сущностей

**Формат имени файла:** `{entityType}_{entityId}.json`

**Примеры:**
- `deal_123.json`
- `task_456.json`

**Подробности:** `12-state-tracking.md`

#### logs/field-changes/

**Назначение:** Изменения полей сущностей

**Формат имени файла:** `{entityType}_{entityId}_{timestamp}.json`

**Примеры:**
- `deal_123_20260126_120500.json`

**Файл:** `field-changes.log` — общий лог всех изменений (line-based JSON)

**Подробности:** `12-state-tracking.md`

---

## state/

### Назначение

Состояния для синхронной обработки ActivityFirst.

### Структура

```
state/
├── activity-first-processed/
│   └── {requestId}_{taskId}.json  # Маркировка обработанных событий
└── activity-first-sync.lock      # Lock-файл для rate limiting
```

### activity-first-processed/

**Назначение:** Маркировка событий, обработанных синхронно

**Формат имени файла:** `{requestId}_{taskId}.json`

**Формат:**
```json
{
  "requestId": "abc123...",
  "taskId": "123",
  "processedAt": "2026-01-26T12:00:00+03:00",
  "sync": true
}
```

**Использование:** для предотвращения повторной обработки в очереди

### activity-first-sync.lock

**Назначение:** Lock-файл для rate limiting синхронной обработки

**Механизм:** файловая блокировка через `flock()`

**Подробности:** `13-activity-first-sync.md`

---

## config.local.php

### Назначение

Локальная конфигурация модуля (опционально)

**Приоритет:** ниже переменных окружения (`.env`)

**Формат:**
```php
<?php
return [
    'OUTGOING_WEBHOOK_TOKEN' => 'your-token-here',
    'OUTGOING_WEBHOOK_ALLOWED_IPS' => ['192.168.1.1'],
    // ...
];
```

**Подробности:** `17-configuration.md`

---

## Форматы файлов

### JSON-файлы

**Формат:** JSON с форматированием (`JSON_PRETTY_PRINT`)

**Кодировка:** UTF-8

**Примеры:**
- `raw.json`
- `enriched.json`
- `queue/pending/*.json`
- `state/*.json`

### Лог-файлы (line-based)

**Формат:** одна строка = одна запись (JSON)

**Кодировка:** UTF-8

**Примеры:**
- `event.log`
- `task-details.log`
- `comment-details.log`
- `error-YYYYMMDD.log`
- `queue-steps.log`
- `activity-first.log`
- `activity-first-metrics.log`
- `field-changes.log`

---

## Правила именования

### Файлы заданий очереди

**Формат:** `{YYYYMMDD}_{HHMMSS}_{EVENT_TYPE}_{ENTITY_ID}.json`

**Примеры:**
- `20260126_120000_ONTASKADD_123.json`
- `20260126_120530_ONCRMDEALUPDATE_456.json`

### Файлы состояний

**Формат:** `{entityType}_{entityId}.json`

**Примеры:**
- `deal_123.json`
- `task_456.json`

### Файлы изменений полей

**Формат:** `{entityType}_{entityId}_{YYYYMMDD}_{HHMMSS}.json`

**Примеры:**
- `deal_123_20260126_120500.json`

### Файлы ошибок

**Формат:** `error-{YYYYMMDD}.log`

**Примеры:**
- `error-20260126.log`

### Файлы ActivityFirst

**Формат:** `{requestId}_{taskId}.json`

**Примеры:**
- `abc123def456_123.json`

---

## Размеры файлов

### Типичные размеры

- **raw.json:** 1-10 KB
- **enriched.json:** 5-50 KB (зависит от размера сущности и справочников)
- **Задание очереди:** 2-15 KB
- **Состояние:** 5-50 KB
- **Изменения полей:** 1-10 KB

### Рекомендации по очистке

- **done/:** можно удалять задания старше 30 дней
- **failed/:** рекомендуется хранить минимум 7 дней
- **state/:** можно удалять состояния удалённых сущностей
- **field-changes/:** рекомендуется хранить минимум 30 дней

---

## Права доступа

### Рекомендации

**Директории:**
- `queue/`, `logs/`, `state/` — права `775` (rwxrwxr-x)

**Файлы:**
- JSON-файлы — права `664` (rw-rw-r--)
- Лог-файлы — права `664` (rw-rw-r--)

**Конфигурация:**
- `config.local.php` — права `600` (rw-------)

---

## Связанные документы

- `05-services-architecture.md` — архитектура сервисов
- `10-queue-detailed.md` — детальное описание очереди
- `12-state-tracking.md` — отслеживание изменений
- `17-configuration.md` — конфигурация

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием структуры файлов и директорий.
