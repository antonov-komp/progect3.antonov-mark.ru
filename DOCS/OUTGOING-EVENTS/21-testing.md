# Тестирование модуля outgoing-webhook

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает подходы к тестированию модуля `outgoing-webhook`: использование инструментов тестирования, тестирование отдельных компонентов, интеграционное тестирование, тестирование очереди и ActivityFirst.

---

## Инструменты тестирования

### test-token-events.php

**Файл:** `outgoing-webhook/tools/test-token-events.php`

**Назначение:** Тестирование обработки событий через отправку тестовых запросов к эндпоинту.

**Использование:**
```bash
# Тестирование одного события
php outgoing-webhook/tools/test-token-events.php --event=ONTASKADD

# Тестирование нескольких событий
php outgoing-webhook/tools/test-token-events.php --events=ONTASKADD,ONTASKUPDATE

# Тестирование всех событий из документации
php outgoing-webhook/tools/test-token-events.php

# С очисткой логов и очереди
php outgoing-webhook/tools/test-token-events.php --event=ONTASKADD --cleanup --cleanup-logs
```

**Подробности:** `15-tools-test-events.md`

### services-smoke.php

**Файл:** `outgoing-webhook/tests/services-smoke.php`

**Назначение:** Дымовые тесты сервисов (smoke tests) для проверки базовой функциональности.

**Использование:**
```bash
php outgoing-webhook/tests/services-smoke.php
```

**Тестируемые компоненты:**
- `LogValueFormatter` — маскирование и нормализация
- `RequestService` — нормализация eventType, getFirstValue, generateRequestId
- `EntityIdentityService` — извлечение entityId, нормализация
- `TaskDetailsService` — extractDealIds
- `CommentDetailsService` — resolveKind

**Формат вывода:**
- При успехе: `OK\n`
- При ошибке: исключение с описанием

---

## Тестирование отдельных компонентов

### Тестирование сервисов

#### ConfigService

**Тестирование загрузки конфигурации:**
```php
$config = new ConfigService();
$token = $config->get('OUTGOING_WEBHOOK_TOKEN');
assert($token !== null, 'Token should be configured');
```

#### FilesystemService

**Тестирование записи файлов:**
```php
$filesystem = new FilesystemService();
$success = $filesystem->writeJson('/tmp/test.json', ['key' => 'value']);
assert($success === true, 'File should be written');
```

#### RequestService

**Тестирование нормализации:**
```php
$request = new RequestService($config);
$normalized = $request->normalizeEventType(' on task ');
assert($normalized === 'ONTASK', 'Event type should be normalized');
```

#### EntityIdentityService

**Тестирование извлечения ID:**
```php
$identity = new EntityIdentityService($request);
$payload = ['data' => ['FIELDS' => ['ID' => '123']]];
$entityId = $identity->extractEntityId($payload);
assert($entityId === '123', 'Entity ID should be extracted');
```

---

## Интеграционное тестирование

### Тестирование эндпоинта

**Шаги:**
1. Настроить токен в `OUTGOING_WEBHOOK_TOKEN`
2. Отправить тестовый запрос через `test-token-events.php`
3. Проверить создание задания в `queue/pending/`
4. Проверить запись `raw.json` и `event.log`

**Пример:**
```bash
# Отправка тестового запроса
php outgoing-webhook/tools/test-token-events.php --event=ONTASKADD

# Проверка создания задания
ls -la outgoing-webhook/queue/pending/

# Проверка логов
cat outgoing-webhook/logs/ONTASKADD/raw.json
cat outgoing-webhook/logs/ONTASKADD/event.log
```

### Тестирование очереди

**Шаги:**
1. Создать тестовое задание в `queue/pending/`
2. Запустить обработчик очереди
3. Проверить перемещение задания в `done/` или `failed/`
4. Проверить создание `enriched.json`

**Пример:**
```bash
# Создание тестового задания (вручную или через test-token-events.php)
php outgoing-webhook/tools/test-token-events.php --event=ONCRMDEALADD

# Запуск обработчика
php outgoing-webhook/tools/process-queue-cli.php --limit=1

# Проверка результата
ls -la outgoing-webhook/queue/done/
cat outgoing-webhook/logs/ONCRMDEALADD/enriched.json
```

### Тестирование ActivityFirst

**Шаги:**
1. Настроить условия в `activity/first/conditions.php`
2. Отправить событие `ONTASKCOMMENTADD` с комментарием, содержащим ключевое слово
3. Проверить синхронную обработку
4. Проверить метрики в `activity-first-metrics.log`

**Пример:**
```bash
# Отправка тестового события (через test-token-events.php или реальное событие)
# Проверка метрик
tail -f outgoing-webhook/logs/activity-first-metrics.log

# Проверка логов ActivityFirst
tail -f outgoing-webhook/logs/activity-first.log
```

---

## Тестирование сценариев

### Сценарий 1: Обработка события сделки

**Шаги:**
1. Отправить событие `ONCRMDEALADD`
2. Проверить создание задания в очереди
3. Запустить обработчик очереди
4. Проверить обогащение данных
5. Проверить загрузку справочников (categories, stages)

**Ожидаемый результат:**
- Задание обработано успешно
- `enriched.json` содержит данные сделки и справочники
- Задание перемещено в `done/`

### Сценарий 2: Обработка события задачи

**Шаги:**
1. Отправить событие `ONTASKADD`
2. Проверить создание задания в очереди
3. Проверить запись `task-details.log`
4. Запустить обработчик очереди
5. Проверить обогащение данных

**Ожидаемый результат:**
- Задание обработано успешно
- `task-details.log` содержит детали задачи
- `enriched.json` содержит данные задачи

### Сценарий 3: Обработка комментария с ActivityFirst

**Шаги:**
1. Настроить условия ActivityFirst
2. Отправить событие `ONTASKCOMMENTADD` с комментарием, содержащим ключевое слово
3. Проверить синхронную обработку
4. Проверить прикрепление файлов к задаче
5. Проверить обновление файлов сделки

**Ожидаемый результат:**
- Комментарий обработан синхронно
- Файлы прикреплены к задаче
- Файлы обновлены в сделке
- Метрики записаны в `activity-first-metrics.log`

### Сценарий 4: Обработка ошибок

**Шаги:**
1. Отправить событие с неверным токеном
2. Проверить возврат ошибки `403`
3. Проверить логирование ошибки
4. Отправить событие с неверным IP (если настроено)
5. Проверить возврат ошибки `403`

**Ожидаемый результат:**
- Ошибки возвращаются с правильными HTTP-кодами
- Ошибки логируются в `logs/errors/error-YYYYMMDD.log`

---

## Отладка

### Проверка логов

**Основные логи:**
```bash
# Логи событий
tail -f outgoing-webhook/logs/ONTASKADD/event.log
tail -f outgoing-webhook/logs/ONTASKADD/raw.json

# Логи ошибок
tail -f outgoing-webhook/logs/errors/error-$(date +%Y%m%d).log

# Логи шагов очереди
tail -f outgoing-webhook/logs/queue-steps.log

# Метрики ActivityFirst
tail -f outgoing-webhook/logs/activity-first-metrics.log
```

### Проверка очереди

```bash
# Количество заданий в очереди
ls -1 outgoing-webhook/queue/pending/ | wc -l
ls -1 outgoing-webhook/queue/processing/ | wc -l
ls -1 outgoing-webhook/queue/done/ | wc -l
ls -1 outgoing-webhook/queue/failed/ | wc -l

# Просмотр failed-заданий
cat outgoing-webhook/queue/failed/*.error.json
```

### Проверка конфигурации

```bash
# Проверка токена
php -r "require 'outgoing-webhook/bootstrap.php'; echo outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN') ? 'OK' : 'MISSING';"

# Проверка разрешённых IP
php -r "require 'outgoing-webhook/bootstrap.php'; print_r(outgoingWebhookGetAllowedIps());"
```

---

## Рекомендации по тестированию

### Перед развёртыванием

1. **Запустить smoke tests:**
   ```bash
   php outgoing-webhook/tests/services-smoke.php
   ```

2. **Протестировать все события:**
   ```bash
   php outgoing-webhook/tools/test-token-events.php --cleanup --cleanup-logs
   ```

3. **Проверить обработку очереди:**
   ```bash
   php outgoing-webhook/tools/process-queue-cli.php --limit=10
   ```

### После изменений в коде

1. **Запустить smoke tests**
2. **Протестировать затронутые компоненты**
3. **Проверить обработку затронутых событий**

### Регулярное тестирование

**Рекомендуется:**
- Еженедельно проверять работу всех событий
- Ежемесячно анализировать метрики производительности
- Регулярно проверять failed-задания

---

## Связанные документы

- `14-tools-allowed-events.md` — инструмент allowed-events
- `15-tools-test-events.md` — инструмент test-token-events
- `16-tools-queue-processors.md` — обработчики очереди
- `20-performance-metrics.md` — метрики производительности

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием тестирования модуля.
