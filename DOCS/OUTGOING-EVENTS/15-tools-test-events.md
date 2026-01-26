# Инструмент test-token-events: тестирование событий

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает инструмент `test-token-events.php` для тестирования событий: отправка тестовых запросов к эндпоинту, чтение событий из документации, очистка логов и очереди, генерация отчёта.

---

## Расположение

**Файл:** `outgoing-webhook/tools/test-token-events.php`

**Доступ:** через CLI

**Пример использования:**
```bash
php outgoing-webhook/tools/test-token-events.php --event=ONTASKADD
```

---

## Назначение

Инструмент отправляет тестовые запросы к эндпоинту `outgoing-webhook/index.php` для проверки обработки событий. Поддерживает чтение событий из документации, очистку логов и очереди, генерацию отчёта.

---

## Параметры запуска

### --endpoint

**Тип:** `string`  
**По умолчанию:** `'http://localhost/outgoing-webhook/index.php'`  
**Описание:** URL эндпоинта для тестирования

**Пример:**
```bash
php test-token-events.php --endpoint="https://your-domain.com/outgoing-webhook/index.php"
```

### --event

**Тип:** `string`  
**Описание:** Одно событие для тестирования

**Пример:**
```bash
php test-token-events.php --event=ONTASKADD
```

### --events

**Тип:** `string` (список через запятую)  
**Описание:** Несколько событий для тестирования

**Пример:**
```bash
php test-token-events.php --events=ONTASKADD,ONTASKUPDATE,ONTASKCOMMENTADD
```

**Примечание:** Если не указаны `--event` или `--events`, события читаются из документации `DOCS/OUTGOING-EVENTS/02-registered-events.md`

### --timeout

**Тип:** `int` (секунды)  
**По умолчанию:** `10`  
**Описание:** Таймаут запроса

**Пример:**
```bash
php test-token-events.php --event=ONTASKADD --timeout=30
```

### --report

**Тип:** `string` (путь к файлу)  
**Описание:** Путь для сохранения отчёта

**Пример:**
```bash
php test-token-events.php --event=ONTASKADD --report=/tmp/test-report.json
```

### --cleanup

**Тип:** флаг (без значения)  
**Описание:** Очистка очереди после тестирования

**Пример:**
```bash
php test-token-events.php --event=ONTASKADD --cleanup
```

**Действие:** удаляет задания, созданные во время тестирования, из `queue/pending/`

### --cleanup-logs

**Тип:** флаг (без значения)  
**Описание:** Очистка логов после тестирования

**Пример:**
```bash
php test-token-events.php --event=ONTASKADD --cleanup-logs
```

**Действие:** восстанавливает состояние логов до тестирования (удаляет записи, добавленные во время тестирования)

---

## Алгоритм работы

### Шаг 1: Получение токена

```php
$token = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN');
if ($token === null || $token === '') {
    outgoingWebhookJsonResponse(500, ['error' => 'missing_token']);
    exit(1);
}
```

**Требование:** токен должен быть настроен в `OUTGOING_WEBHOOK_TOKEN`

### Шаг 2: Определение событий для тестирования

**Приоритет:**
1. Параметр `--event`
2. Параметр `--events`
3. Чтение из документации `DOCS/OUTGOING-EVENTS/02-registered-events.md`

**Функция чтения из документации:**
```php
function testTokenEventsReadEventsFromDocs(string $path): array
{
    $content = file_get_contents($path);
    preg_match_all('/\b(ON[A-Z0-9_]+|SONET_GROUP_[A-Z_]+)\b/', $content, $matches);
    $events = $matches[1] ?? [];
    $events = array_values(array_unique(array_filter($events, 'is_string')));
    sort($events, SORT_STRING);
    return $events;
}
```

**Алгоритм:**
- Поиск всех событий через регулярное выражение
- Удаление дубликатов
- Сортировка по алфавиту

### Шаг 3: Создание снимков логов (если --cleanup-logs)

```php
if ($cleanupLogs) {
    $eventDir = dirname(__DIR__) . '/logs/' . $event;
    $rawPath = $eventDir . '/raw.json';
    $eventLogPath = $eventDir . '/event.log';
    $snapshots[$event] = [
        'raw' => testTokenEventsSnapshotFile($rawPath),
        'eventLog' => testTokenEventsSnapshotFile($eventLogPath),
    ];
}
```

**Функция `testTokenEventsSnapshotFile()`:**
- Сохраняет текущее состояние файла
- Возвращает: `['exists' => bool, 'size' => int, 'content' => string|null]`

### Шаг 4: Отправка тестовых запросов

```php
foreach ($events as $event) {
    $payload = [
        'event' => $event,
        'event_handler_id' => 'test-handler',
        'token' => $token,
        'auth' => [
            'application_token' => $token,
            'member_id' => 'test-member',
        ],
        'data' => [],
    ];
    $response = testTokenEventsRequest($endpoint, $payload, $timeout);
    $report['results'][] = [
        'event' => $event,
        'status' => $response['status'],
        'error' => $response['error'],
    ];
}
```

**Функция `testTokenEventsRequest()`:**
- Отправка POST-запроса с JSON payload
- Поддержка cURL и `file_get_contents()`
- Возврат: `['status' => int, 'error' => string|null, 'body' => string|null]`

### Шаг 5: Очистка очереди (если --cleanup)

```php
if ($cleanup) {
    $queueAfter = testTokenEventsListFiles($queueDir);
    $newQueueFiles = array_diff($queueAfter, $queueBefore);
    foreach ($newQueueFiles as $file) {
        @unlink($file);
    }
}
```

**Алгоритм:**
1. Получение списка файлов до тестирования
2. Получение списка файлов после тестирования
3. Удаление новых файлов (созданных во время тестирования)

### Шаг 6: Восстановление логов (если --cleanup-logs)

```php
if ($cleanupLogs) {
    foreach ($snapshots as $event => $snapshot) {
        testTokenEventsRestoreFile($rawPath, $snapshot['raw'] ?? []);
        $eventLogSnapshot = $snapshot['eventLog'] ?? ['exists' => false, 'size' => 0];
        if (!($eventLogSnapshot['exists'] ?? false)) {
            if (file_exists($eventLogPath)) {
                @unlink($eventLogPath);
            }
        } else {
            $size = (int) ($eventLogSnapshot['size'] ?? 0);
            testTokenEventsTruncateFile($eventLogPath, $size);
        }
    }
}
```

**Алгоритм:**
1. Восстановление `raw.json` из снимка
2. Обрезка `event.log` до исходного размера (или удаление, если файла не было)

### Шаг 7: Сохранение отчёта (если --report)

```php
if (isset($options['report']) && is_string($options['report']) && $options['report'] !== '') {
    $reportPath = $options['report'];
    outgoingWebhookSafeMkdir(dirname($reportPath));
    outgoingWebhookWriteJson($reportPath, $report);
}
```

### Шаг 8: Возврат результата

```php
outgoingWebhookJsonResponse(200, $report);
```

---

## Формат отчёта

### Структура

```json
{
  "generatedAt": "2026-01-26T12:00:00+03:00",
  "endpoint": "http://localhost/outgoing-webhook/index.php",
  "eventsCount": 3,
  "cleanup": true,
  "cleanupLogs": false,
  "results": [
    {
      "event": "ONTASKADD",
      "status": 200,
      "error": null
    },
    {
      "event": "ONTASKUPDATE",
      "status": 200,
      "error": null
    },
    {
      "event": "ONTASKCOMMENTADD",
      "status": 403,
      "error": "invalid_token"
    }
  ]
}
```

### Описание полей

| Поле | Тип | Описание |
|------|-----|----------|
| `generatedAt` | string | Время генерации отчёта (ISO 8601) |
| `endpoint` | string | URL эндпоинта |
| `eventsCount` | int | Количество протестированных событий |
| `cleanup` | bool | Была ли выполнена очистка очереди |
| `cleanupLogs` | bool | Была ли выполнена очистка логов |
| `results` | array | Результаты тестирования каждого события |

### Формат результата события

```json
{
  "event": "ONTASKADD",
  "status": 200,
  "error": null
}
```

**Возможные статусы:**
- `200` — успешно обработано
- `400` — неверный payload
- `403` — неверный токен или IP не разрешён
- `405` — метод не разрешён
- `413` — payload слишком большой
- `500` — внутренняя ошибка сервера
- `0` — ошибка сети или таймаут

---

## Примеры использования

### Тестирование одного события

```bash
php outgoing-webhook/tools/test-token-events.php --event=ONTASKADD
```

### Тестирование нескольких событий

```bash
php outgoing-webhook/tools/test-token-events.php --events=ONTASKADD,ONTASKUPDATE,ONTASKCOMMENTADD
```

### Тестирование всех событий из документации

```bash
php outgoing-webhook/tools/test-token-events.php
```

### Тестирование с очисткой

```bash
php outgoing-webhook/tools/test-token-events.php --event=ONTASKADD --cleanup --cleanup-logs
```

### Тестирование с сохранением отчёта

```bash
php outgoing-webhook/tools/test-token-events.php --event=ONTASKADD --report=/tmp/test-report.json
```

### Тестирование с кастомным endpoint

```bash
php outgoing-webhook/tools/test-token-events.php \
  --event=ONTASKADD \
  --endpoint="https://your-domain.com/outgoing-webhook/index.php" \
  --timeout=30
```

---

## Вспомогательные функции

### testTokenEventsRequest()

Отправка HTTP-запроса.

**Поддержка:**
- cURL (если доступен)
- `file_get_contents()` (fallback)

**Параметры:**
- `$endpoint` — URL эндпоинта
- `$payload` — данные для отправки
- `$timeoutSeconds` — таймаут в секундах

**Возвращает:** `['status' => int, 'error' => string|null, 'body' => string|null]`

### testTokenEventsSnapshotFile()

Создание снимка файла.

**Параметры:**
- `$path` — путь к файлу

**Возвращает:** `['exists' => bool, 'size' => int, 'content' => string|null]`

### testTokenEventsRestoreFile()

Восстановление файла из снимка.

**Параметры:**
- `$path` — путь к файлу
- `$snapshot` — снимок файла

**Действие:**
- Если файл был — восстанавливает содержимое
- Если файла не было — удаляет файл (если существует)

### testTokenEventsTruncateFile()

Обрезка файла до указанного размера.

**Параметры:**
- `$path` — путь к файлу
- `$size` — размер в байтах

**Использование:** для восстановления `event.log` до исходного размера

### testTokenEventsListFiles()

Получение списка файлов в директории.

**Параметры:**
- `$dir` — путь к директории

**Возвращает:** массив путей к файлам

---

## Обработка ошибок

### Отсутствие токена

**Код:** `500`

**Формат:**
```json
{
  "error": "missing_token"
}
```

**Действие:** завершение выполнения с кодом `1`

### Отсутствие событий

**Код:** `400`

**Формат:**
```json
{
  "error": "no_events"
}
```

**Действие:** завершение выполнения с кодом `1`

---

## Рекомендации

### Когда использовать

1. **При настройке модуля:**
   - Проверить обработку всех событий
   - Убедиться, что эндпоинт работает корректно

2. **После изменений в коде:**
   - Проверить, что обработка событий не сломалась
   - Убедиться, что валидация работает

3. **При диагностике проблем:**
   - Проверить обработку конкретного события
   - Убедиться, что токен и IP настроены правильно

### Использование с очисткой

**Рекомендуется:**
- Использовать `--cleanup` и `--cleanup-logs` для тестирования
- Это предотвращает загрязнение логов и очереди тестовыми данными

### Сохранение отчётов

**Рекомендуется:**
- Сохранять отчёты для сравнения результатов
- Использовать для мониторинга изменений в обработке событий

---

## Связанные документы

- `02-registered-events.md` — список событий (источник для автоматического чтения)
- `01-how-it-works.md` — общий поток обработки
- `17-configuration.md` — конфигурация модуля (настройка токена)

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием инструмента test-token-events.
