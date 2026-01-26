# План дополнительной документации модуля outgoing-webhook

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Статус:** План  
**Автор:** Технический писатель

## Цель

Составить план документации для модуля `outgoing-webhook`, покрывающий все неописанные компоненты и функциональность на основе анализа кодовой базы.

---

## Анализ существующей документации

### Что уже описано

1. ✅ **Общий поток работы** (`01-how-it-works.md`)
   - Приём события, очередь, обогащение
   - Детали по задачам и комментариям

2. ✅ **Зарегистрированные события** (`02-registered-events.md`)
   - Список событий и карта обогащения

3. ✅ **Расшифровка payload** (`03-payload-decoding.md`)
   - Нормализация eventType, извлечение entityId

4. ✅ **Activity First** (`04-activity-first.md`)
   - Условия срабатывания

5. ✅ **Bootstrap** (`bootstrap/README.md`)
   - Описание shim-функций

6. ✅ **Логи** (`outgoing-webhook-logs.md`)
   - Структура логов

7. ✅ **TASK-014 документы**
   - Различные этапы разработки

---

## Что не описано (пробелы в документации)

### 1. Архитектура сервисов

**Пробел:** Нет общего описания архитектуры сервисного слоя.

**Что нужно описать:**
- Структура папки `services/`
- Принципы Dependency Injection
- Связи между сервисами
- Паттерны проектирования (Service Layer, Repository, Handler)

**Файл:** `DOCS/OUTGOING-EVENTS/05-services-architecture.md`

**Содержание:**
- Обзор структуры `services/`
- Диаграмма зависимостей сервисов
- Принципы работы сервисного слоя
- Связь с bootstrap.php (shim-функции)

---

### 2. Детальное описание сервисов

**Пробел:** Каждый сервис описан только в коде, нет документации.

#### 2.1. Core Services

**ConfigService** (`services/Config/ConfigService.php`)
- Загрузка конфигурации из .env и config.local.php
- Методы получения настроек
- Приоритеты источников конфигурации

**FilesystemService** (`services/Core/FilesystemService.php`)
- Работа с файловой системой
- Создание директорий, запись JSON, append строк
- Обработка ошибок файловых операций

**RequestService** (`services/Http/RequestService.php`)
- Чтение payload из запроса
- Нормализация eventType
- Генерация requestId
- Формирование JSON-ответов
- Работа с временем

**AccessService** (`services/Security/AccessService.php`)
- Валидация токена
- Проверка IP-адресов
- Извлечение auth-информации из payload
- Безопасность

**EntityIdentityService** (`services/Identity/EntityIdentityService.php`)
- Извлечение entityId из payload
- Нормализация entityId
- Определение entityType по eventType
- Извлечение commentId, messageId, taskId

**ErrorService** (`services/Logging/ErrorService.php`)
- Логирование ошибок
- Формат логов ошибок
- Интеграция с error_log

**LogValueFormatter** (`services/Logging/LogValueFormatter.php`)
- Маскирование чувствительных данных
- Нормализация значений для логов
- Формат маскирования токенов

**RestService** (`services/Rest/RestService.php`)
- Обёртка над Bitrix24Client
- Обработка REST-запросов
- Логирование REST-вызовов
- Обработка ошибок API

**DictCacheService** (`services/Dicts/DictCacheService.php`)
- Кеширование справочников
- TTL для кеша
- Методы получения справочников
- Инвалидация кеша

**Файл:** `DOCS/OUTGOING-EVENTS/06-core-services.md`

---

#### 2.2. Enrichment Services

**EnrichmentService** (`services/Enrichment/EnrichmentService.php`)
- Процесс обогащения данных
- Маппинг eventType → REST метод
- Интеграция с EntityHandlers
- Сохранение enriched.json
- Отслеживание изменений полей

**EntityHandlers** (`services/Enrichment/EntityHandlers/`)
- Интерфейс EntityHandlerInterface
- Реализации для каждого типа сущности:
  - DealHandler
  - LeadHandler
  - ContactHandler
  - CompanyHandler
  - SmartProcessHandler
  - TaskHandler
  - UserHandler
  - ProjectHandler
  - CrmUserFieldHandler
  - DefaultEntityHandler
- Построение параметров для REST-запросов
- Получение справочников для сущностей

**StateStorage** (`services/Enrichment/StateStorage.php`)
- Хранение снимков состояния сущностей
- Сравнение состояний
- Определение изменений полей

**ValueComparator** (`services/Enrichment/ValueComparator.php`)
- Сравнение значений
- Рекурсивная сортировка
- Определение различий

**Файл:** `DOCS/OUTGOING-EVENTS/07-enrichment-services.md`

---

#### 2.3. Queue Services

**QueueService** (`services/Queue/QueueService.php`)
- Управление очередью (pending, processing, done, failed)
- Список заданий
- Подсчёт заданий

**QueueJob** (`services/Queue/QueueJob.php`)
- Представление задания очереди
- Валидация задания
- Работа с данными задания

**QueueRunner** (`services/Queue/QueueRunner.php`)
- Оркестрация обработки очереди
- Интеграция с EnrichmentService
- Обработка задач и комментариев
- Логирование шагов обработки

**JobStateService** (`services/Queue/JobStateService.php`)
- Управление состоянием заданий
- Максимальное количество попыток
- Таймаут обработки
- Восстановление зависших заданий (recoverProcessing)

**QueueStepLogger** (`services/Logging/QueueStepLogger.php`)
- Логирование шагов обработки очереди
- Формат логов шагов

**Файл:** `DOCS/OUTGOING-EVENTS/08-queue-services.md`

---

#### 2.4. Task Services

**TaskDetailsService** (`services/Task/TaskDetailsService.php`)
- Извлечение данных задачи из enriched
- Построение деталей задачи
- Форматирование деталей для логов
- Загрузка CRM-связей задачи
- Оценка Activity First
- Логирование Activity First
- Маркировка обработанных Activity First

**TaskFilesService** (`services/Task/TaskFilesService.php`)
- Получение прикреплённых файлов задачи
- Прикрепление файлов к задаче
- Получение информации о файле из Disk

**DealFileService** (`services/Crm/DealFileService.php`)
- Работа с файлами сделок
- Получение файлового поля сделки
- Обновление файлов сделки
- Построение данных файла из записи сделки

**CommentDetailsService** (`services/Task/CommentDetailsService.php`)
- Получение деталей комментария
- Fallback на чат (im.dialog.messages.get)
- Построение деталей комментария
- Форматирование деталей для логов
- Запись обогащённых данных комментария
- **Синхронная обработка ActivityFirst** (processActivityFirst)
- Прикрепление файлов к задаче
- Обновление файлов сделок

**Файл:** `DOCS/OUTGOING-EVENTS/09-task-services.md`

---

### 3. Очередь (детальное описание)

**Пробел:** Есть общее описание, но нет деталей работы очереди.

**Что нужно описать:**
- Жизненный цикл задания (pending → processing → done/failed)
- Формат задания очереди
- Обработка ошибок и повторные попытки
- Восстановление зависших заданий
- CLI-обработчик очереди
- Rate limiting и таймауты

**Файл:** `DOCS/OUTGOING-EVENTS/10-queue-detailed.md`

**Содержание:**
- Структура папок очереди
- Формат JSON-файла задания
- Алгоритм обработки задания
- Обработка ошибок и повторные попытки
- Восстановление зависших заданий
- CLI-запуск (`process-queue-cli.php`)
- HTTP-запуск (`process-queue.php`)
- Параметры и лимиты

---

### 4. Обогащение данных (детальное описание)

**Пробел:** Есть общее описание, но нет деталей процесса обогащения.

**Что нужно описать:**
- Полный процесс обогащения от raw до enriched
- Маппинг событий на REST-методы
- Работа EntityHandlers
- Загрузка справочников
- Отслеживание изменений полей
- Формат enriched.json

**Файл:** `DOCS/OUTGOING-EVENTS/11-enrichment-detailed.md`

**Содержание:**
- Алгоритм обогащения
- Маппинг eventType → REST метод
- Параметры REST-запросов для каждого типа сущности
- Загрузка справочников (воронки, стадии, статусы)
- Сравнение состояний и определение изменений
- Формат enriched.json
- Обработка ошибок обогащения

---

### 5. StateStorage и отслеживание изменений

**Пробел:** Не описано, как работает отслеживание изменений полей.

**Что нужно описать:**
- Хранение снимков состояния
- Алгоритм сравнения состояний
- Определение изменённых полей
- Формат файлов состояния
- Логирование изменений полей

**Файл:** `DOCS/OUTGOING-EVENTS/12-state-tracking.md`

**Содержание:**
- Принцип работы StateStorage
- Формат файлов состояния
- Алгоритм сравнения состояний
- Логирование изменений полей
- Примеры изменений

---

### 6. Синхронная обработка ActivityFirst

**Пробел:** Есть описание условий ActivityFirst, но нет описания синхронной обработки.

**Что нужно описать:**
- Синхронная обработка в `index.php` (после отправки ответа)
- Использование `fastcgi_finish_request()`
- Rate limiting для синхронной обработки
- Процесс прикрепления файлов к задаче
- Процесс обновления файлов сделок
- Логирование и метрики

**Файл:** `DOCS/OUTGOING-EVENTS/13-activity-first-sync.md`

**Содержание:**
- Алгоритм синхронной обработки
- Использование fastcgi_finish_request
- Rate limiting (lock-файл)
- Процесс обработки (прикрепление файлов, обновление сделок)
- Логирование результатов
- Метрики производительности
- Обработка ошибок

---

### 7. Инструменты и утилиты

**Пробел:** Инструменты не описаны.

#### 7.1. allowed-events.php

**Что нужно описать:**
- Назначение скрипта
- Использование REST API `event.get`
- Формат вывода
- Генерация JSON-файлов

**Файл:** `DOCS/OUTGOING-EVENTS/14-tools-allowed-events.md`

---

#### 7.2. test-token-events.php

**Что нужно описать:**
- Назначение скрипта (тестирование событий)
- Параметры запуска
- Чтение событий из документации
- Отправка тестовых запросов
- Очистка логов и очереди
- Формат отчёта

**Файл:** `DOCS/OUTGOING-EVENTS/15-tools-test-events.md`

---

#### 7.3. process-queue.php и process-queue-cli.php

**Что нужно описать:**
- HTTP-обработчик очереди
- CLI-обработчик очереди
- Параметры (limit)
- Формат ответа
- Использование в cron

**Файл:** `DOCS/OUTGOING-EVENTS/16-tools-queue-processors.md`

---

### 8. Конфигурация

**Пробел:** Нет описания всех настроек модуля.

**Что нужно описать:**
- Переменные окружения (.env)
- Файл config.local.php
- Все настройки модуля:
  - OUTGOING_WEBHOOK_TOKEN
  - OUTGOING_WEBHOOK_ALLOWED_IPS
  - OUTGOING_WEBHOOK_MAX_BYTES
  - ACTIVITY_FIRST_SYNC_ENABLED
  - ACTIVITY_FIRST_SYNC_RATE_LIMIT
  - И другие
- Приоритеты источников конфигурации

**Файл:** `DOCS/OUTGOING-EVENTS/17-configuration.md`

**Содержание:**
- Список всех настроек
- Описание каждой настройки
- Значения по умолчанию
- Примеры конфигурации
- Безопасность (хранение токенов)

---

### 9. Обработка ошибок

**Пробел:** Нет систематического описания обработки ошибок.

**Что нужно описать:**
- Стратегия обработки ошибок
- Логирование ошибок
- Обработка ошибок REST API
- Обработка ошибок файловых операций
- Обработка ошибок очереди
- Восстановление после ошибок

**Файл:** `DOCS/OUTGOING-EVENTS/18-error-handling.md`

**Содержание:**
- Классификация ошибок
- Стратегии обработки
- Логирование ошибок
- Примеры обработки ошибок
- Восстановление после ошибок

---

### 10. Безопасность

**Пробел:** Нет систематического описания безопасности.

**Что нужно описать:**
- Валидация токена
- Проверка IP-адресов
- Маскирование чувствительных данных
- Защита от переполнения payload
- Защита от повторных запросов
- Безопасное хранение конфигурации

**Файл:** `DOCS/OUTGOING-EVENTS/19-security.md`

**Содержание:**
- Механизмы безопасности
- Валидация входящих данных
- Защита от атак
- Рекомендации по настройке

---

### 11. Производительность и метрики

**Пробел:** Нет описания метрик и производительности.

**Что нужно описать:**
- Метрики обработки очереди
- Метрики ActivityFirst (activity-first-metrics.log)
- Производительность REST-запросов
- Оптимизация обработки
- Рекомендации по настройке

**Файл:** `DOCS/OUTGOING-EVENTS/20-performance-metrics.md`

**Содержание:**
- Метрики модуля
- Формат логов метрик
- Анализ производительности
- Рекомендации по оптимизации

---

### 12. Тестирование

**Пробел:** Нет описания тестирования модуля.

**Что нужно описать:**
- Использование test-token-events.php
- Тестирование отдельных компонентов
- Интеграционное тестирование
- Тестирование очереди
- Тестирование ActivityFirst

**Файл:** `DOCS/OUTGOING-EVENTS/21-testing.md`

**Содержание:**
- Инструменты тестирования
- Примеры тестов
- Тестирование сценариев
- Отладка

---

### 13. Интеграция с Bitrix24 REST API

**Пробел:** Нет систематического описания используемых методов API.

**Что нужно описать:**
- Все используемые методы Bitrix24 REST API
- Параметры запросов
- Формат ответов
- Обработка ошибок API
- Ссылки на документацию

**Файл:** `DOCS/OUTGOING-EVENTS/22-bitrix24-api-integration.md`

**Содержание:**
- Список методов API
- Описание каждого метода
- Примеры запросов/ответов
- Обработка ошибок
- Ссылки на документацию Bitrix24

---

### 14. Структура файлов и директорий

**Пробел:** Нет полного описания структуры модуля.

**Что нужно описать:**
- Полная структура папок и файлов
- Назначение каждой директории
- Формат файлов
- Правила именования

**Файл:** `DOCS/OUTGOING-EVENTS/23-file-structure.md`

**Содержание:**
- Дерево директорий
- Описание каждой директории
- Формат файлов
- Правила именования

---

## Приоритеты документации

### Высокий приоритет (критично для понимания модуля)

1. **05-services-architecture.md** — Архитектура сервисов
2. **06-core-services.md** — Core Services
3. **08-queue-services.md** — Queue Services
4. **10-queue-detailed.md** — Очередь (детальное описание)
5. **17-configuration.md** — Конфигурация

### Средний приоритет (важно для работы с модулем)

6. **07-enrichment-services.md** — Enrichment Services
7. **09-task-services.md** — Task Services
8. **11-enrichment-detailed.md** — Обогащение (детальное описание)
9. **13-activity-first-sync.md** — Синхронная обработка ActivityFirst
10. **18-error-handling.md** — Обработка ошибок
11. **19-security.md** — Безопасность

### Низкий приоритет (полезно для расширенного использования)

12. **12-state-tracking.md** — StateStorage
13. **14-tools-allowed-events.md** — Инструмент allowed-events
14. **15-tools-test-events.md** — Инструмент test-token-events
15. **16-tools-queue-processors.md** — Обработчики очереди
16. **20-performance-metrics.md** — Производительность
17. **21-testing.md** — Тестирование
18. **22-bitrix24-api-integration.md** — Интеграция с API
19. **23-file-structure.md** — Структура файлов

---

## План реализации

### Этап 1: Архитектура и Core Services (1-2 дня)
- 05-services-architecture.md
- 06-core-services.md

### Этап 2: Очередь и обогащение (2-3 дня)
- 08-queue-services.md
- 10-queue-detailed.md
- 07-enrichment-services.md
- 11-enrichment-detailed.md

### Этап 3: Task Services и ActivityFirst (1-2 дня)
- 09-task-services.md
- 13-activity-first-sync.md

### Этап 4: Конфигурация и безопасность (1 день)
- 17-configuration.md
- 19-security.md
- 18-error-handling.md

### Этап 5: Инструменты и дополнительные темы (1-2 дня)
- 14-tools-allowed-events.md
- 15-tools-test-events.md
- 16-tools-queue-processors.md
- 12-state-tracking.md
- 20-performance-metrics.md
- 21-testing.md
- 22-bitrix24-api-integration.md
- 23-file-structure.md

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан план документации на основе анализа кодовой базы модуля outgoing-webhook.
