# Core Services: базовые сервисы модуля

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает базовые сервисы модуля `outgoing-webhook`, которые используются всеми остальными компонентами: конфигурация, файловые операции, HTTP-запросы, безопасность, идентификация сущностей и логирование.

---

## ConfigService

**Файл:** `outgoing-webhook/services/Config/ConfigService.php`

### Назначение

Управление конфигурацией модуля. Загружает настройки из переменных окружения (`.env`) и файла `config.local.php` с приоритетом переменных окружения.

### Методы

#### `getEnv(string $key, ?string $default = null): ?string`

Получение значения из переменных окружения.

**Параметры:**
- `$key` — ключ переменной окружения
- `$default` — значение по умолчанию (если не найдено)

**Возвращает:** значение переменной или `$default`

**Пример:**
```php
$token = $config->getEnv('OUTGOING_WEBHOOK_TOKEN');
```

#### `getConfig(): array`

Загрузка конфигурации из файла `config.local.php`.

**Возвращает:** массив конфигурации или пустой массив

**Кеширование:** результат кешируется в `$configCache`

**Пример файла `config.local.php`:**
```php
<?php
return [
    'OUTGOING_WEBHOOK_TOKEN' => 'your-token-here',
    'OUTGOING_WEBHOOK_ALLOWED_IPS' => ['192.168.1.1', '10.0.0.1'],
];
```

#### `get(string $key, ?string $default = null): ?string`

Получение настройки с приоритетом: переменная окружения → config.local.php → default.

**Параметры:**
- `$key` — ключ настройки
- `$default` — значение по умолчанию

**Возвращает:** значение настройки или `$default`

**Приоритет источников:**
1. Переменная окружения (`.env`)
2. Файл `config.local.php`
3. Значение по умолчанию

**Пример:**
```php
$token = $config->get('OUTGOING_WEBHOOK_TOKEN');
```

#### `getArray(string $key): array`

Получение настройки как массива. Поддерживает строку с запятыми или массив.

**Параметры:**
- `$key` — ключ настройки

**Возвращает:** массив значений

**Примеры:**
```php
// В .env: OUTGOING_WEBHOOK_ALLOWED_IPS=192.168.1.1,10.0.0.1
$ips = $config->getArray('OUTGOING_WEBHOOK_ALLOWED_IPS');
// Результат: ['192.168.1.1', '10.0.0.1']

// В config.local.php: 'OUTGOING_WEBHOOK_ALLOWED_IPS' => ['192.168.1.1', '10.0.0.1']
$ips = $config->getArray('OUTGOING_WEBHOOK_ALLOWED_IPS');
// Результат: ['192.168.1.1', '10.0.0.1']
```

#### `getClientEndpoint(): ?string`

Получение endpoint клиента из `app/settings.json`.

**Возвращает:** URL endpoint или `null`

**Кеширование:** результат кешируется в `$clientEndpointCache`

**Формат `app/settings.json`:**
```json
{
  "client_endpoint": "https://your-domain.bitrix24.ru"
}
```

#### `getMaxBytes(): int`

Получение максимального размера payload (2 MB).

**Возвращает:** `2097152` (2 MB)

**Константа:** `ConfigService::MAX_BYTES`

---

## FilesystemService

**Файл:** `outgoing-webhook/services/Core/FilesystemService.php`

### Назначение

Операции с файловой системой: создание директорий, запись JSON, добавление строк в файлы, загрузка файлов по URL.

### Методы

#### `ensureDir(string $path): void`

Создание директории, если она не существует.

**Параметры:**
- `$path` — путь к директории

**Права доступа:** `0775` (rwxrwxr-x)

**Рекурсивное создание:** да (`mkdir(..., true)`)

**Пример:**
```php
$filesystem->ensureDir('/path/to/directory');
```

#### `writeJson(string $path, array $data): bool`

Запись массива в JSON-файл с форматированием.

**Параметры:**
- `$path` — путь к файлу
- `$data` — данные для записи

**Возвращает:** `true` при успехе, `false` при ошибке

**Особенности:**
- Автоматически создаёт директорию, если не существует
- Использует `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`
- Добавляет `PHP_EOL` в конец файла
- При ошибке `json_encode` записывает `{"error": "json_encode_failed"}`

**Пример:**
```php
$success = $filesystem->writeJson('/path/to/file.json', ['key' => 'value']);
```

#### `appendLine(string $path, string $line): bool`

Добавление строки в конец файла с блокировкой.

**Параметры:**
- `$path` — путь к файлу
- `$line` — строка для добавления

**Возвращает:** `true` при успехе, `false` при ошибке

**Особенности:**
- Автоматически создаёт директорию, если не существует
- Использует `flock()` для блокировки файла (важно после `fastcgi_finish_request()`)
- Выполняет `fflush()` для явной синхронизации на диск
- Добавляет `PHP_EOL` в конец строки

**Пример:**
```php
$success = $filesystem->appendLine('/path/to/log.log', 'Log entry');
```

#### `downloadBase64(string $url): ?string`

Загрузка файла по URL и кодирование в Base64.

**Параметры:**
- `$url` — URL файла

**Возвращает:** Base64-строка или `null` при ошибке

**Использование:** для загрузки файлов из Bitrix24 Disk

**Пример:**
```php
$base64 = $filesystem->downloadBase64('https://example.com/file.pdf');
```

---

## RequestService

**Файл:** `outgoing-webhook/services/Http/RequestService.php`

### Назначение

Работа с HTTP-запросами: чтение payload, нормализация eventType, генерация requestId, формирование JSON-ответов, работа с временем.

### Зависимости

- `ConfigService` — для получения client endpoint

### Методы

#### `readPayload(): array`

Чтение payload из HTTP-запроса.

**Возвращает:** массив данных payload

**Приоритет источников:**
1. JSON (`Content-Type: application/json`)
2. `$_POST`
3. `parse_str()` из raw body

**Пример:**
```php
$payload = $request->readPayload();
```

#### `jsonResponse(int $statusCode, array $payload): void`

Отправка JSON-ответа с указанным HTTP-кодом.

**Параметры:**
- `$statusCode` — HTTP-код ответа (200, 400, 403, 500 и т.д.)
- `$payload` — данные для отправки

**Особенности:**
- Устанавливает `Content-Type: application/json; charset=UTF-8`
- Использует `JSON_UNESCAPED_SLASHES`

**Пример:**
```php
$request->jsonResponse(200, ['status' => 'ok']);
```

#### `normalizeEventType(?string $event): string`

Нормализация типа события.

**Параметры:**
- `$event` — исходный тип события

**Возвращает:** нормализованный тип события или `'UNKNOWN'`

**Алгоритм:**
1. Приведение к верхнему регистру
2. Удаление пробелов
3. Удаление всех символов, кроме `A-Z`, `0-9` и `_`
4. Если результат пустой — возврат `'UNKNOWN'`

**Примеры:**
```php
$request->normalizeEventType('OnCrmDealAdd'); // 'ONCRMDEALADD'
$request->normalizeEventType('on crm deal add'); // 'ONCRMDEALADD'
$request->normalizeEventType(''); // 'UNKNOWN'
```

#### `resolveAbsoluteUrl(string $url): string`

Преобразование относительного URL в абсолютный.

**Параметры:**
- `$url` — относительный или абсолютный URL

**Возвращает:** абсолютный URL

**Логика:**
- Если URL уже абсолютный (`http://` или `https://`) — возврат как есть
- Если URL не начинается с `/` — возврат как есть
- Иначе — добавление схемы и хоста из `client_endpoint` (settings.json)

**Пример:**
```php
$absolute = $request->resolveAbsoluteUrl('/path/to/resource');
// Результат: 'https://your-domain.bitrix24.ru/path/to/resource'
```

#### `getFirstValue(array $data, array $keys)`

Получение первого найденного значения по списку ключей.

**Параметры:**
- `$data` — массив данных
- `$keys` — массив ключей или путей (может быть вложенным массивом)

**Возвращает:** первое найденное значение или `null`

**Поддержка вложенных путей:**
```php
// Простой ключ
$value = $request->getFirstValue($data, ['ID', 'id']);

// Вложенный путь
$value = $request->getFirstValue($data, [['FIELDS', 'ID'], ['FIELDS_AFTER', 'ID']]);
```

**Пример:**
```php
$entityId = $request->getFirstValue($payload, [
    ['FIELDS_AFTER', 'ID'],
    ['FIELDS', 'ID'],
    'ID'
]);
```

#### `generateRequestId(): string`

Генерация уникального ID запроса.

**Возвращает:** 32-символьная hex-строка (16 байт)

**Использование:** для отслеживания запросов в логах

**Пример:**
```php
$requestId = $request->generateRequestId();
// Результат: 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6'
```

#### `now(): string`

Получение текущего времени в формате ISO 8601.

**Возвращает:** строка формата `2026-01-26T12:00:00+03:00`

**Использование:** для логирования временных меток

**Пример:**
```php
$timestamp = $request->now();
// Результат: '2026-01-26T12:00:00+03:00'
```

---

## AccessService

**Файл:** `outgoing-webhook/services/Security/AccessService.php`

### Назначение

Безопасность: валидация токенов, проверка IP-адресов, извлечение auth-информации из payload.

### Зависимости

- `ConfigService` — для получения настроек безопасности

### Методы

#### `getAllowedIps(): array`

Получение списка разрешённых IP-адресов.

**Возвращает:** массив IP-адресов

**Источники:**
- Переменная окружения `OUTGOING_WEBHOOK_ALLOWED_IPS` (строка с запятыми)
- Файл `config.local.php` (массив или строка)

**Обработка:**
- Удаление пустых значений
- Удаление дубликатов
- Trim значений

**Пример:**
```php
$allowedIps = $access->getAllowedIps();
// Результат: ['192.168.1.1', '10.0.0.1']
```

**Использование:**
```php
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$allowedIps = $access->getAllowedIps();
if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
    // IP не разрешён
}
```

#### `extractAuthToken(array $payload): string`

Извлечение токена из payload.

**Параметры:**
- `$payload` — payload запроса

**Возвращает:** токен или пустая строка

**Приоритет источников:**
1. `$payload['token']`
2. `$payload['auth']['application_token']`
3. `$payload['auth']['app_token']`

**Пример:**
```php
$token = $access->extractAuthToken($payload);
```

#### `extractAuthInfo(array $payload): array`

Извлечение токена и источника из payload.

**Параметры:**
- `$payload` — payload запроса

**Возвращает:** массив `['token' => string, 'source' => string]`

**Возможные значения `source`:**
- `'token'` — из `$payload['token']`
- `'auth.application_token'` — из `$payload['auth']['application_token']`
- `'auth.app_token'` — из `$payload['auth']['app_token']`
- `'none'` — токен не найден

**Пример:**
```php
$authInfo = $access->extractAuthInfo($payload);
// Результат: ['token' => 'abc123...', 'source' => 'auth.application_token']
```

**Использование:** для логирования источника токена

---

## EntityIdentityService

**Файл:** `outgoing-webhook/services/Identity/EntityIdentityService.php`

### Назначение

Извлечение и нормализация идентификаторов сущностей из payload: entityId, commentId, taskId, messageId, определение entityType.

### Зависимости

- `RequestService` — для использования `getFirstValue()`

### Методы

#### `extractEntityId(array $payload): ?string`

Извлечение ID сущности из payload.

**Параметры:**
- `$payload` — payload запроса

**Возвращает:** ID сущности или `null`

**Приоритет источников (в `payload.data`):**
1. `FIELDS_AFTER.TASK_ID`
2. `FIELDS.TASK_ID`
3. `TASK_ID`
4. `FIELDS.ID`
5. `FIELDS_AFTER.ID`
6. `FIELDS_BEFORE.ID`
7. `ID`

**Пример:**
```php
$entityId = $identity->extractEntityId($payload);
```

#### `extractCommentId(array $payload): ?string`

Извлечение ID комментария из payload.

**Параметры:**
- `$payload` — payload запроса

**Возвращает:** ID комментария или `null`

**Приоритет источников (в `payload.data`):**
1. `FIELDS_AFTER.MESSAGE_ID`
2. `FIELDS.MESSAGE_ID`
3. `FIELDS_AFTER.ID`
4. `FIELDS.ID`
5. `MESSAGE_ID`
6. `ID`

**Пример:**
```php
$commentId = $identity->extractCommentId($payload);
```

#### `normalizeEntityId(?string $entityId): ?string`

Нормализация ID сущности.

**Параметры:**
- `$entityId` — исходный ID

**Возвращает:** нормализованный ID или `null`

**Правила:**
- Пустые значения → `null`
- `"0"` → `null`
- Trim пробелов

**Пример:**
```php
$normalized = $identity->normalizeEntityId('  123  '); // '123'
$normalized = $identity->normalizeEntityId('0'); // null
$normalized = $identity->normalizeEntityId(''); // null
```

#### `extractTaskId(array $payload): ?string`

Извлечение ID задачи из payload.

**Параметры:**
- `$payload` — payload запроса

**Возвращает:** ID задачи или `null`

**Приоритет источников (в `payload.data`):**
1. `FIELDS_AFTER.TASK_ID`
2. `FIELDS.TASK_ID`
3. `TASK_ID`

**Пример:**
```php
$taskId = $identity->extractTaskId($payload);
```

#### `extractMessageId(array $payload): ?string`

Извлечение ID сообщения из payload.

**Параметры:**
- `$payload` — payload запроса

**Возвращает:** ID сообщения или `null`

**Приоритет источников (в `payload.data`):**
1. `FIELDS_AFTER.MESSAGE_ID`
2. `FIELDS.MESSAGE_ID`
3. `MESSAGE_ID`

**Пример:**
```php
$messageId = $identity->extractMessageId($payload);
```

#### `resolveEntityType(string $eventType): string`

Определение типа сущности по типу события.

**Параметры:**
- `$eventType` — тип события (например, `'ONCRMDEALADD'`)

**Возвращает:** тип сущности (например, `'deal'`)

**Маппинг:**
- `ONCRMDEAL*` → `'deal'`
- `ONCRMLEAD*` → `'lead'`
- `ONCRMCONTACT*` → `'contact'`
- `ONCRMCOMPANY*` → `'company'`
- `ONCRMITEM*` → `'smart_process'`
- `ONTASK*` → `'task'`
- `ONUSER*` → `'user'`
- `SONET_GROUP_*` → `'project'`
- `ONCRMUSERFIELD*` → `'crm_userfield'`
- Иначе → `'unknown'`

**Пример:**
```php
$entityType = $identity->resolveEntityType('ONCRMDEALADD');
// Результат: 'deal'
```

---

## ErrorService

**Файл:** `outgoing-webhook/services/Logging/ErrorService.php`

### Назначение

Логирование ошибок в файл и `error_log`.

### Зависимости

- `FilesystemService` — для записи в файл
- `RequestService` — для получения текущего времени

### Методы

#### `log(string $message, array $context = []): void`

Логирование ошибки.

**Параметры:**
- `$message` — сообщение об ошибке
- `$context` — контекст (массив дополнительных данных)

**Формат записи:**
- Файл: `outgoing-webhook/logs/errors/error-YYYYMMDD.log` (line-based JSON)
- `error_log`: `[outgoing-webhook] {message} {context_json}`

**Формат записи в файл:**
```json
{
  "loggedAt": "2026-01-26T12:00:00+03:00",
  "message": "Error message",
  "context": {
    "key": "value"
  }
}
```

**Пример:**
```php
$errors->log('REST call failed', [
    'method' => 'crm.deal.get',
    'error' => 'invalid_token',
    'entityId' => '123'
]);
```

---

## LogValueFormatter

**Файл:** `outgoing-webhook/services/Logging/LogValueFormatter.php`

### Назначение

Форматирование и маскирование значений для логов.

### Методы

#### `maskValue(string $value): string`

Маскирование чувствительного значения.

**Параметры:**
- `$value` — исходное значение

**Возвращает:** маскированное значение (формат: `****{последние_4_символа}`)

**Пример:**
```php
$formatter->maskValue('abc123xyz789');
// Результат: '****z789'
```

#### `maskPayload(array $payload): array`

Маскирование токенов в payload.

**Параметры:**
- `$payload` — payload для маскирования

**Возвращает:** payload с замаскированными токенами

**Маскируются ключи:**
- `token`
- `auth.application_token`
- `auth.app_token`

**Рекурсивная обработка:** да (обрабатывает вложенные массивы)

**Пример:**
```php
$payload = [
    'token' => 'secret-token-1234',
    'auth' => [
        'application_token' => 'app-token-5678'
    ]
];
$masked = $formatter->maskPayload($payload);
// Результат:
// [
//     'token' => '****1234',
//     'auth' => [
//         'application_token' => '****5678'
//     ]
// ]
```

#### `normalize($value): string`

Нормализация значения для логов.

**Параметры:**
- `$value` — значение для нормализации

**Возвращает:** нормализованная строка

**Правила:**
- `null` → `'unknown'`
- Пустая строка → `'unknown'`
- Удаление лишних пробелов (замена на один пробел)
- Trim пробелов

**Пример:**
```php
$formatter->normalize(null); // 'unknown'
$formatter->normalize(''); // 'unknown'
$formatter->normalize('  multiple   spaces  '); // 'multiple spaces'
```

---

## RestService

**Файл:** `outgoing-webhook/services/Rest/RestService.php`

### Назначение

Обёртка над `Bitrix24Client` с поддержкой повторных попыток и логированием ошибок.

### Зависимости

- `Bitrix24Client` — клиент Bitrix24 REST API
- `ConfigService` — для получения настроек повторных попыток
- `ErrorService` — для логирования ошибок

### Методы

#### `call(string $method, array $params = []): array`

Вызов метода Bitrix24 REST API с поддержкой повторных попыток.

**Параметры:**
- `$method` — метод API (например, `'crm.deal.get'`)
- `$params` — параметры запроса

**Возвращает:** результат вызова API

**Настройки повторных попыток:**
- `OUTGOING_WEBHOOK_REST_RETRIES` — количество повторных попыток (по умолчанию: `0`)
- `OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS` — задержка между попытками в миллисекундах (по умолчанию: `0`)

**Логика:**
1. Выполнение запроса через `Bitrix24Client`
2. Если ошибка и есть попытки — повтор с задержкой
3. Если все попытки исчерпаны — логирование ошибки
4. Возврат результата (с ошибкой или без)

**Пример:**
```php
$result = $rest->call('crm.deal.get', ['id' => '123']);
if (!empty($result['error'])) {
    // Обработка ошибки
}
```

---

## DictCacheService

**Файл:** `outgoing-webhook/services/Dicts/DictCacheService.php`

### Назначение

Кеширование справочников Bitrix24 (воронки, стадии, статусы) с TTL.

### Зависимости

- `RestService` — для получения справочников через REST API
- `ErrorService` — для логирования ошибок

### Методы

#### `read(string $path, int $ttlSeconds): ?array`

Чтение справочника из кеша.

**Параметры:**
- `$path` — путь к файлу кеша
- `$ttlSeconds` — время жизни кеша в секундах

**Возвращает:** данные справочника или `null` (если кеш устарел или не существует)

**Формат файла кеша:**
```json
{
  "cachedAt": "2026-01-26T12:00:00+03:00",
  "data": {
    // Данные справочника
  }
}
```

**Пример:**
```php
$dict = $dicts->read('/path/to/dict.json', 3600);
```

#### `write(string $path, array $data): void`

Запись справочника в кеш.

**Параметры:**
- `$path` — путь к файлу кеша
- `$data` — данные справочника

**Пример:**
```php
$dicts->write('/path/to/dict.json', $data);
```

#### `get(string $name, string $method, array $params, int $ttlSeconds): ?array`

Получение справочника с автоматическим кешированием.

**Параметры:**
- `$name` — имя справочника (используется для имени файла)
- `$method` — метод Bitrix24 REST API
- `$params` — параметры запроса
- `$ttlSeconds` — время жизни кеша в секундах

**Возвращает:** данные справочника или `null` при ошибке

**Алгоритм:**
1. Попытка чтения из кеша
2. Если кеш валиден — возврат данных
3. Если кеш устарел или не существует — запрос через REST API
4. Сохранение в кеш
5. Возврат данных

**Пример:**
```php
$stages = $dicts->get(
    'deal-stages',
    'crm.status.list',
    ['filter' => ['ENTITY_ID' => 'DEAL_STAGE']],
    3600
);
```

---

## Использование в bootstrap.php

Все Core Services доступны через shim-функции в `bootstrap.php`:

```php
// ConfigService
$token = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN');

// FilesystemService
outgoingWebhookSafeMkdir($path);
outgoingWebhookWriteJson($path, $data);
outgoingWebhookAppendLine($path, $line);

// RequestService
$payload = outgoingWebhookReadPayload();
$eventType = outgoingWebhookNormalizeEventType($event);
$requestId = outgoingWebhookGenerateRequestId();

// AccessService
$allowedIps = outgoingWebhookGetAllowedIps();
$authInfo = outgoingWebhookExtractAuthInfo($payload);

// EntityIdentityService
$entityId = outgoingWebhookExtractEntityId($payload);
$entityType = outgoingWebhookResolveEntityType($eventType);

// ErrorService
outgoingWebhookLogError('Error message', ['context' => 'value']);

// LogValueFormatter
$masked = outgoingWebhookMaskPayload($payload);
$normalized = outgoingWebhookNormalizeLogValue($value);

// RestService
$result = outgoingWebhookRestCall('crm.deal.get', ['id' => '123']);

// DictCacheService
$dict = outgoingWebhookGetDict('name', 'method', $params, 3600);
```

---

## Связанные документы

- `05-services-architecture.md` — общая архитектура сервисов
- `07-enrichment-services.md` — сервисы обогащения
- `08-queue-services.md` — сервисы очереди
- `09-task-services.md` — сервисы задач

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием Core Services.
