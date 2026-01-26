# Безопасность модуля outgoing-webhook

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает механизмы безопасности модуля `outgoing-webhook`: валидация токена, проверка IP-адресов, маскирование чувствительных данных, защита от переполнения payload, безопасное хранение конфигурации.

---

## Валидация токена

### Механизм валидации

**Файл:** `outgoing-webhook/index.php`

**Алгоритм:**
1. Получение ожидаемого токена из конфигурации
2. Извлечение токена из payload
3. Сравнение через `hash_equals()`

**Код:**
```php
$expectedToken = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN');
if ($expectedToken === null) {
    outgoingWebhookLogError('Missing OUTGOING_WEBHOOK_TOKEN env');
    outgoingWebhookJsonResponse(500, ['error' => 'server_not_configured']);
    exit;
}

$authInfo = outgoingWebhookExtractAuthInfo($payload);
if (!hash_equals($expectedToken, $authInfo['token'])) {
    outgoingWebhookLogError('Invalid token', [
        'ip' => $clientIp,
        'requestId' => $requestId,
        'token_source' => $authInfo['source'],
    ]);
    outgoingWebhookJsonResponse(403, ['error' => 'invalid_token']);
    exit;
}
```

### Безопасное сравнение

**Использование `hash_equals()`:**
- Защита от timing attacks
- Сравнение за постоянное время
- Рекомендуется для сравнения секретов

**Альтернатива (не используется):**
```php
// НЕ БЕЗОПАСНО:
if ($expectedToken === $authInfo['token']) {
    // Уязвимо к timing attacks
}
```

### Источники токена в payload

**Приоритет извлечения:**
1. `payload.token`
2. `payload.auth.application_token`
3. `payload.auth.app_token`

**Логирование источника:**
- Источник токена логируется для отладки
- Не влияет на валидацию

---

## Проверка IP-адресов

### Механизм проверки

**Файл:** `outgoing-webhook/index.php`

**Настройка:** `OUTGOING_WEBHOOK_ALLOWED_IPS`

**Код:**
```php
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$allowedIps = outgoingWebhookGetAllowedIps();
if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
    outgoingWebhookLogError('IP not allowed', ['ip' => $clientIp, 'requestId' => $requestId]);
    outgoingWebhookJsonResponse(403, ['error' => 'ip_not_allowed']);
    exit;
}
```

### Особенности

1. **Опциональная проверка:**
   - Если `OUTGOING_WEBHOOK_ALLOWED_IPS` не задан — проверка не выполняется
   - Разрешены все IP-адреса

2. **Строгое сравнение:**
   - Используется `in_array(..., true)` для строгого сравнения
   - Учитывается точное совпадение IP-адреса

3. **Логирование:**
   - Все попытки доступа с неразрешённых IP логируются

### Рекомендации

**Для продакшена:**
- Настроить `OUTGOING_WEBHOOK_ALLOWED_IPS` с IP-адресами Bitrix24
- Или использовать firewall на уровне веб-сервера

**Пример конфигурации:**
```env
OUTGOING_WEBHOOK_ALLOWED_IPS=1.2.3.4,5.6.7.8
```

---

## Маскирование чувствительных данных

### Маскирование токенов

**Сервис:** `LogValueFormatter`

**Алгоритм:**
```php
public function maskValue(string $value): string
{
    $suffix = substr($value, -4);
    return '****' . $suffix;
}
```

**Формат:** `****{последние_4_символа}`

**Пример:**
```php
$masked = $formatter->maskValue('abc123xyz789def456ghi012');
// Результат: '****i012'
```

### Маскирование payload

**Метод:** `maskPayload(array $payload): array`

**Маскируются ключи:**
- `token`
- `auth.application_token`
- `auth.app_token`

**Рекурсивная обработка:**
- Обрабатываются вложенные массивы
- Маскируются все вхождения токенов

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

### Где применяется маскирование

1. **Запись raw.json:**
   ```php
   $maskedPayload = outgoingWebhookMaskPayload($payload);
   $raw['payload'] = $maskedPayload;
   outgoingWebhookWriteJson($rawPath, $raw);
   ```

2. **Запись задания очереди:**
   ```php
   $queueItem['payload'] = $maskedPayload;
   ```

3. **Логирование:**
   - Все токены в логах маскируются автоматически

---

## Защита от переполнения payload

### Ограничение размера

**Константа:** `OUTGOING_WEBHOOK_MAX_BYTES = 2097152` (2 MB)

**Проверка:**
```php
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > OUTGOING_WEBHOOK_MAX_BYTES) {
    outgoingWebhookJsonResponse(413, ['error' => 'payload_too_large']);
    exit;
}
```

**HTTP-код:** `413 Payload Too Large`

**Рекомендации:**
- Размер 2 MB достаточен для большинства событий Bitrix24
- При необходимости можно увеличить константу

---

## Безопасное хранение конфигурации

### Источники конфигурации

1. **Переменные окружения (`.env`)** — высший приоритет
2. **Файл `config.local.php`** — средний приоритет
3. **Значения по умолчанию** — низший приоритет

### Хранение токенов

**Рекомендации:**
- Хранить токены только в `.env` или `config.local.php`
- Не коммитить `.env` в репозиторий (добавить в `.gitignore`)
- Использовать разные токены для разных окружений

**Пример `.gitignore`:**
```
.env
outgoing-webhook/config.local.php
```

### Права доступа к файлам

**Рекомендации:**
- Файл `config.local.php` должен быть доступен только для чтения веб-серверу
- Права: `640` (rw-r-----) или `600` (rw-------)

**Команда:**
```bash
chmod 600 outgoing-webhook/config.local.php
```

### Защита от утечек

1. **Маскирование в логах:**
   - Все токены автоматически маскируются при записи в логи

2. **Отсутствие токенов в коде:**
   - Токены не хранятся в коде
   - Только в конфигурационных файлах

3. **Безопасные ответы:**
   - Ответы не содержат чувствительных данных
   - Ошибки не раскрывают детали конфигурации

---

## Защита от атак

### Timing Attacks

**Защита:** использование `hash_equals()` для сравнения токенов

**Принцип:**
- Сравнение выполняется за постоянное время
- Невозможно определить, на каком символе произошло несовпадение

### SQL Injection

**Защита:** модуль не использует прямые SQL-запросы
- Все данные хранятся в JSON-файлах
- Нет работы с БД через SQL

### XSS (Cross-Site Scripting)

**Защита:**
- Все данные записываются в JSON-файлы (не в HTML)
- Нет вывода данных в HTML без экранирования

### Path Traversal

**Защита:**
- Все пути формируются программно
- Нет использования пользовательских данных в путях файлов

**Пример:**
```php
$eventDir = __DIR__ . '/logs/' . $eventType; // $eventType нормализуется
$rawPath = $eventDir . '/raw.json'; // Путь формируется программно
```

### DoS (Denial of Service)

**Защита:**
1. **Ограничение размера payload:** 2 MB
2. **Rate limiting для ActivityFirst:** через lock-файл
3. **Таймаут обработки:** 15 минут для заданий очереди

---

## Валидация входных данных

### Нормализация eventType

```php
public function normalizeEventType(?string $event): string
{
    $event = $event ?? '';
    $event = strtoupper(trim(str_replace(' ', '', $event)));
    $event = preg_replace('/[^A-Z0-9_]/', '', $event);
    return $event !== '' ? $event : 'UNKNOWN';
}
```

**Защита:**
- Удаление всех символов, кроме `A-Z`, `0-9` и `_`
- Предотвращение path traversal через eventType

### Нормализация entityId

```php
public function normalizeEntityId(?string $entityId): ?string
{
    if ($entityId === null) {
        return null;
    }

    $value = trim((string) $entityId);
    if ($value === '' || $value === '0') {
        return null;
    }

    return $value;
}
```

**Защита:**
- Trim пробелов
- Валидация пустых значений

---

## Логирование безопасности

### Логирование попыток доступа

**Все попытки доступа логируются:**

1. **Неверный токен:**
   ```php
   outgoingWebhookLogError('Invalid token', [
       'ip' => $clientIp,
       'requestId' => $requestId,
       'token_source' => $authInfo['source'],
   ]);
   ```

2. **IP не разрешён:**
   ```php
   outgoingWebhookLogError('IP not allowed', ['ip' => $clientIp, 'requestId' => $requestId]);
   ```

3. **Payload слишком большой:**
   ```php
   outgoingWebhookLogError('Payload too large', ['size' => $contentLength]);
   ```

**Файл:** `logs/errors/error-YYYYMMDD.log`

### Мониторинг безопасности

**Рекомендации:**
1. Регулярная проверка логов на подозрительную активность
2. Мониторинг количества ошибок `invalid_token` и `ip_not_allowed`
3. Анализ IP-адресов, с которых приходят запросы

---

## Рекомендации по настройке безопасности

### Для продакшена

1. **Настроить токен:**
   ```env
   OUTGOING_WEBHOOK_TOKEN=strong-random-token-here
   ```

2. **Ограничить IP-адреса:**
   ```env
   OUTGOING_WEBHOOK_ALLOWED_IPS=1.2.3.4,5.6.7.8
   ```

3. **Защитить конфигурационные файлы:**
   ```bash
   chmod 600 .env
   chmod 600 outgoing-webhook/config.local.php
   ```

4. **Использовать HTTPS:**
   - Все запросы должны идти по HTTPS
   - Настроить SSL-сертификат на веб-сервере

5. **Настроить firewall:**
   - Ограничить доступ к эндпоинту на уровне веб-сервера
   - Использовать fail2ban для блокировки подозрительных IP

### Для разработки

1. **Использовать тестовый токен:**
   - Отдельный токен для разработки
   - Не использовать продакшн-токен

2. **Логирование:**
   - Включить подробное логирование для отладки
   - Проверять логи на наличие ошибок безопасности

---

## Связанные документы

- `06-core-services.md` — базовые сервисы (AccessService, LogValueFormatter)
- `17-configuration.md` — конфигурация модуля
- `18-error-handling.md` — обработка ошибок

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием механизмов безопасности.
