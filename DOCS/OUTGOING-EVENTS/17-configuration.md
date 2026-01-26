# Конфигурация модуля outgoing-webhook

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает все настройки модуля `outgoing-webhook`: переменные окружения, файл конфигурации, приоритеты источников и примеры настройки.

---

## Источники конфигурации

### Приоритет источников

1. **Переменные окружения** (`.env`) — высший приоритет
2. **Файл `config.local.php`** — средний приоритет
3. **Значения по умолчанию** — низший приоритет

### Переменные окружения (.env)

Файл `.env` в корне проекта:

```env
OUTGOING_WEBHOOK_TOKEN=your-token-here
OUTGOING_WEBHOOK_ALLOWED_IPS=192.168.1.1,10.0.0.1
ACTIVITY_FIRST_SYNC_ENABLED=true
ACTIVITY_FIRST_SYNC_RATE_LIMIT=5
OUTGOING_WEBHOOK_REST_RETRIES=2
OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS=1000
OUTGOING_WEBHOOK_COMMENT_ENRICHED_TASK_ID=123
```

### Файл config.local.php

Файл `outgoing-webhook/config.local.php`:

```php
<?php
return [
    'OUTGOING_WEBHOOK_TOKEN' => 'your-token-here',
    'OUTGOING_WEBHOOK_ALLOWED_IPS' => ['192.168.1.1', '10.0.0.1'],
    'ACTIVITY_FIRST_SYNC_ENABLED' => 'true',
    'ACTIVITY_FIRST_SYNC_RATE_LIMIT' => '5',
    'OUTGOING_WEBHOOK_REST_RETRIES' => '2',
    'OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS' => '1000',
    'OUTGOING_WEBHOOK_COMMENT_ENRICHED_TASK_ID' => '123',
];
```

**Примечание:** Значения могут быть строками или массивами (для `OUTGOING_WEBHOOK_ALLOWED_IPS`).

---

## Список настроек

### OUTGOING_WEBHOOK_TOKEN

**Тип:** `string`  
**Обязательная:** Да  
**Описание:** Токен для валидации входящих запросов от Bitrix24.

**Использование:**
- Валидация токена в `index.php`
- Сравнение с токеном из payload через `hash_equals()`

**Пример:**
```env
OUTGOING_WEBHOOK_TOKEN=abc123xyz789def456ghi012
```

**Безопасность:**
- Токен маскируется при записи в логи (формат: `****{последние_4_символа}`)
- Хранится только в `.env` или `config.local.php` (не в коде)

**Где используется:**
- `outgoing-webhook/index.php` — валидация токена
- `outgoing-webhook/tools/test-token-events.php` — тестирование событий

---

### OUTGOING_WEBHOOK_ALLOWED_IPS

**Тип:** `array|string`  
**Обязательная:** Нет  
**Описание:** Список разрешённых IP-адресов для входящих запросов.

**Формат:**
- Строка с запятыми: `"192.168.1.1,10.0.0.1"`
- Массив: `['192.168.1.1', '10.0.0.1']`

**Использование:**
- Проверка IP-адреса клиента в `index.php`
- Если список пустой — проверка не выполняется (разрешены все IP)

**Пример:**
```env
OUTGOING_WEBHOOK_ALLOWED_IPS=192.168.1.1,10.0.0.1,172.16.0.1
```

**Или в config.local.php:**
```php
'OUTGOING_WEBHOOK_ALLOWED_IPS' => ['192.168.1.1', '10.0.0.1', '172.16.0.1']
```

**Где используется:**
- `outgoing-webhook/index.php` — проверка IP-адреса
- `outgoing-webhook/services/Security/AccessService.php` — получение списка IP

---

### OUTGOING_WEBHOOK_MAX_BYTES

**Тип:** `int` (константа)  
**Обязательная:** Нет (константа)  
**Описание:** Максимальный размер входящего payload в байтах.

**Значение по умолчанию:** `2097152` (2 MB)

**Определение:**
```php
const OUTGOING_WEBHOOK_MAX_BYTES = 2097152; // 2 MB
```

**Использование:**
- Проверка размера payload в `index.php`
- Если размер превышен — возврат HTTP 413 (Payload Too Large)

**Где используется:**
- `outgoing-webhook/bootstrap.php` — определение константы
- `outgoing-webhook/index.php` — проверка размера

---

### OUTGOING_WEBHOOK_MAX_ATTEMPTS

**Тип:** `int` (константа)  
**Обязательная:** Нет (константа)  
**Описание:** Максимальное количество попыток обработки задания очереди.

**Значение по умолчанию:** `3`

**Определение:**
```php
const OUTGOING_WEBHOOK_MAX_ATTEMPTS = 3;
```

**Использование:**
- Управление повторными попытками в `JobStateService`
- При ошибке обогащения задание возвращается в `pending/` с увеличенным счётчиком
- Если попытки исчерпаны — задание перемещается в `failed/`

**Где используется:**
- `outgoing-webhook/tools/process-queue.php` — определение константы
- `outgoing-webhook/services/Queue/JobStateService.php` — управление попытками

---

### OUTGOING_WEBHOOK_PROCESSING_TIMEOUT

**Тип:** `int` (константа)  
**Обязательная:** Нет (константа)  
**Описание:** Таймаут обработки задания в секундах.

**Значение по умолчанию:** `900` (15 минут)

**Определение:**
```php
const OUTGOING_WEBHOOK_PROCESSING_TIMEOUT = 900; // 15 minutes
```

**Использование:**
- Восстановление зависших заданий в `JobStateService::recoverProcessing()`
- Задания в `processing/` старше таймаута возвращаются в `pending/` или перемещаются в `failed/`

**Где используется:**
- `outgoing-webhook/tools/process-queue.php` — определение константы
- `outgoing-webhook/services/Queue/JobStateService.php` — восстановление зависших заданий

---

### ACTIVITY_FIRST_SYNC_ENABLED

**Тип:** `string` (`'true'` или `'false'`)  
**Обязательная:** Нет  
**Описание:** Включение синхронной обработки ActivityFirst.

**Значение по умолчанию:** `'true'`

**Возможные значения:**
- `'true'` или `'1'` — включено
- Иначе — выключено

**Использование:**
- Проверка в `outgoingWebhookProcessActivityFirstSync()`
- Если выключено — синхронная обработка не выполняется

**Пример:**
```env
ACTIVITY_FIRST_SYNC_ENABLED=true
```

**Где используется:**
- `outgoing-webhook/bootstrap.php` — функция `outgoingWebhookProcessActivityFirstSync()`

---

### ACTIVITY_FIRST_SYNC_RATE_LIMIT

**Тип:** `int`  
**Обязательная:** Нет  
**Описание:** Лимит одновременных синхронных обработок ActivityFirst (через lock-файл).

**Значение по умолчанию:** `5`

**Использование:**
- Rate limiting через файловую блокировку (`flock()`)
- Если блокировка не получена — обработка пропускается (логируется как rate limit)

**Пример:**
```env
ACTIVITY_FIRST_SYNC_RATE_LIMIT=5
```

**Где используется:**
- `outgoing-webhook/bootstrap.php` — функция `outgoingWebhookProcessActivityFirstSync()`

---

### OUTGOING_WEBHOOK_REST_RETRIES

**Тип:** `int`  
**Обязательная:** Нет  
**Описание:** Количество повторных попыток при ошибке REST API.

**Значение по умолчанию:** `0` (без повторных попыток)

**Использование:**
- Повторные попытки в `RestService::call()`
- При ошибке REST API выполняется повтор с задержкой

**Пример:**
```env
OUTGOING_WEBHOOK_REST_RETRIES=2
```

**Где используется:**
- `outgoing-webhook/services/Rest/RestService.php` — повторные попытки

---

### OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS

**Тип:** `int`  
**Обязательная:** Нет  
**Описание:** Задержка между повторными попытками REST API в миллисекундах.

**Значение по умолчанию:** `0` (без задержки)

**Использование:**
- Задержка между попытками в `RestService::call()`
- Используется `usleep($delayMs * 1000)`

**Пример:**
```env
OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS=1000
```

**Где используется:**
- `outgoing-webhook/services/Rest/RestService.php` — задержка между попытками

---

### OUTGOING_WEBHOOK_COMMENT_ENRICHED_TASK_ID

**Тип:** `string`  
**Обязательная:** Нет  
**Описание:** ID задачи, для которой нужно записывать обогащённые данные комментариев.

**Использование:**
- Проверка в `CommentDetailsService::shouldWriteEnriched()`
- Если задан — обогащённые данные записываются только для указанной задачи
- Если не задан — обогащённые данные не записываются

**Пример:**
```env
OUTGOING_WEBHOOK_COMMENT_ENRICHED_TASK_ID=123
```

**Где используется:**
- `outgoing-webhook/services/Task/CommentDetailsService.php` — проверка необходимости записи

---

## Примеры конфигурации

### Минимальная конфигурация

**Файл `.env`:**
```env
OUTGOING_WEBHOOK_TOKEN=your-token-here
```

### Полная конфигурация

**Файл `.env`:**
```env
OUTGOING_WEBHOOK_TOKEN=abc123xyz789def456ghi012
OUTGOING_WEBHOOK_ALLOWED_IPS=192.168.1.1,10.0.0.1
ACTIVITY_FIRST_SYNC_ENABLED=true
ACTIVITY_FIRST_SYNC_RATE_LIMIT=5
OUTGOING_WEBHOOK_REST_RETRIES=2
OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS=1000
OUTGOING_WEBHOOK_COMMENT_ENRICHED_TASK_ID=123
```

**Файл `outgoing-webhook/config.local.php`:**
```php
<?php
return [
    'OUTGOING_WEBHOOK_TOKEN' => 'abc123xyz789def456ghi012',
    'OUTGOING_WEBHOOK_ALLOWED_IPS' => ['192.168.1.1', '10.0.0.1'],
    'ACTIVITY_FIRST_SYNC_ENABLED' => 'true',
    'ACTIVITY_FIRST_SYNC_RATE_LIMIT' => '5',
    'OUTGOING_WEBHOOK_REST_RETRIES' => '2',
    'OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS' => '1000',
    'OUTGOING_WEBHOOK_COMMENT_ENRICHED_TASK_ID' => '123',
];
```

### Конфигурация для продакшена

**Рекомендации:**
- Использовать `.env` для секретов (токены)
- Использовать `config.local.php` для настроек, специфичных для окружения
- Ограничить IP-адреса через `OUTGOING_WEBHOOK_ALLOWED_IPS`
- Включить повторные попытки REST API (`OUTGOING_WEBHOOK_REST_RETRIES=2`)

**Пример `.env`:**
```env
OUTGOING_WEBHOOK_TOKEN=production-token-here
OUTGOING_WEBHOOK_ALLOWED_IPS=1.2.3.4,5.6.7.8
OUTGOING_WEBHOOK_REST_RETRIES=3
OUTGOING_WEBHOOK_REST_RETRY_DELAY_MS=2000
```

**Пример `config.local.php`:**
```php
<?php
return [
    'ACTIVITY_FIRST_SYNC_ENABLED' => 'true',
    'ACTIVITY_FIRST_SYNC_RATE_LIMIT' => '10',
];
```

---

## Безопасность

### Хранение токенов

**Рекомендации:**
- Хранить токены только в `.env` или `config.local.php`
- Не коммитить `.env` в репозиторий (добавить в `.gitignore`)
- Использовать разные токены для разных окружений (dev, staging, production)

### Ограничение IP-адресов

**Рекомендации:**
- Настроить `OUTGOING_WEBHOOK_ALLOWED_IPS` в продакшене
- Указать только IP-адреса Bitrix24 (если известны)
- Или использовать firewall на уровне веб-сервера

### Права доступа к файлам

**Рекомендации:**
- Файл `config.local.php` должен быть доступен только для чтения веб-серверу
- Права: `640` (rw-r-----) или `600` (rw-------)

```bash
chmod 600 outgoing-webhook/config.local.php
```

---

## Проверка конфигурации

### Проверка наличия токена

```php
$token = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN');
if ($token === null) {
    // Токен не настроен
}
```

### Проверка разрешённых IP

```php
$allowedIps = outgoingWebhookGetAllowedIps();
if (empty($allowedIps)) {
    // Проверка IP не включена
}
```

### Тестирование конфигурации

Использовать скрипт `test-token-events.php`:

```bash
php outgoing-webhook/tools/test-token-events.php --event=ONTASKADD
```

---

## Связанные документы

- `06-core-services.md` — описание ConfigService
- `19-security.md` — безопасность модуля
- `bootstrap/README.md` — shim-функции для конфигурации

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием всех настроек модуля.
