# Инструмент allowed-events: анализ доступных событий

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает инструмент `allowed-events.php` для анализа доступных событий Bitrix24 по токену: получение списка методов REST API, группировка по категориям, сохранение результатов.

---

## Расположение

**Файл:** `outgoing-webhook/tools/allowed-events.php`

**Доступ:** через HTTP-запрос или CLI

**Пример URL:**
```
https://your-domain.com/outgoing-webhook/tools/allowed-events.php
```

---

## Назначение

Инструмент получает список всех доступных методов Bitrix24 REST API для текущего токена и группирует их по категориям (event, crm, tasks, sonet_group, user).

---

## Алгоритм работы

### Шаг 1: Получение списка методов

```php
$result = outgoingWebhookRestCall('methods');
```

**Метод REST API:** `methods`

**Документация:** https://context7.com/bitrix24/rest/methods

**Возвращает:** массив строк с именами методов

**Пример ответа:**
```json
{
  "result": [
    "crm.deal.add",
    "crm.deal.get",
    "crm.lead.add",
    "event.bind",
    "event.get",
    "tasks.task.get",
    "user.get"
  ]
}
```

### Шаг 2: Обработка ошибок

```php
if (!empty($result['error'])) {
    outgoingWebhookLogError('REST error for methods', ['error' => $result['error']]);
    outgoingWebhookJsonResponse(500, ['error' => 'rest_error', 'details' => $result['error']]);
    exit;
}
```

**Типичные ошибки:**
- `invalid_token` — неверный токен
- `ACCESS_DENIED` — нет доступа к методу `methods`

### Шаг 3: Валидация ответа

```php
$methods = $result['result'] ?? [];
if (!is_array($methods)) {
    outgoingWebhookLogError('Unexpected methods response');
    outgoingWebhookJsonResponse(500, ['error' => 'invalid_methods_response']);
    exit;
}
```

### Шаг 4: Сортировка методов

```php
sort($methods, SORT_STRING);
```

**Результат:** отсортированный по алфавиту массив методов

### Шаг 5: Группировка по категориям

```php
$groups = [
    'event' => [],
    'crm' => [],
    'tasks' => [],
    'sonet_group' => [],
    'user' => [],
];

foreach ($methods as $method) {
    if (!is_string($method)) {
        continue;
    }
    foreach (array_keys($groups) as $group) {
        if (str_starts_with($method, $group . '.')) {
            $groups[$group][] = $method;
            break;
        }
    }
}
```

**Категории:**
- `event` — методы работы с событиями (`event.bind`, `event.get`, `event.unbind`)
- `crm` — методы CRM (`crm.deal.*`, `crm.lead.*`, `crm.contact.*`, и т.д.)
- `tasks` — методы задач (`tasks.task.*`, `task.*`)
- `sonet_group` — методы проектов (`sonet_group.*`)
- `user` — методы пользователей (`user.*`)

**Примечание:** методы, не попадающие ни в одну категорию, не включаются в группы.

### Шаг 6: Сохранение результатов

```php
$logDir = __DIR__ . '/../logs/allowed-events';
outgoingWebhookSafeMkdir($logDir);

outgoingWebhookWriteJson($logDir . '/allowed-events.json', $report);
outgoingWebhookWriteJson($logDir . '/raw-methods.json', ['methods' => $methods]);
```

**Файлы:**
- `logs/allowed-events/allowed-events.json` — сгруппированные методы
- `logs/allowed-events/raw-methods.json` — полный список методов

### Шаг 7: Возврат результата

```php
outgoingWebhookJsonResponse(200, $report);
```

---

## Формат результата

### HTTP-ответ

```json
{
  "generatedAt": "2026-01-26T12:00:00+03:00",
  "groups": {
    "event": [
      "event.bind",
      "event.get",
      "event.unbind"
    ],
    "crm": [
      "crm.deal.add",
      "crm.deal.get",
      "crm.lead.add",
      "crm.lead.get"
    ],
    "tasks": [
      "tasks.task.get",
      "task.item.getdata"
    ],
    "sonet_group": [
      "sonet_group.get"
    ],
    "user": [
      "user.get"
    ]
  }
}
```

### Файл allowed-events.json

**Путь:** `logs/allowed-events/allowed-events.json`

**Формат:** идентичен HTTP-ответу

### Файл raw-methods.json

**Путь:** `logs/allowed-events/raw-methods.json`

**Формат:**
```json
{
  "methods": [
    "crm.deal.add",
    "crm.deal.get",
    "crm.lead.add",
    "event.bind",
    "event.get",
    "tasks.task.get",
    "user.get"
  ]
}
```

---

## Использование

### HTTP-запрос

```bash
curl "https://your-domain.com/outgoing-webhook/tools/allowed-events.php"
```

**Требования:**
- Токен должен быть настроен в `OUTGOING_WEBHOOK_TOKEN`
- Токен должен иметь доступ к методу `methods`

### CLI

```bash
php outgoing-webhook/tools/allowed-events.php
```

**Примечание:** для CLI требуется настройка переменных окружения или `config.local.php`

---

## Анализ результатов

### Проверка доступных событий

**События доступны, если есть методы:**
- `event.bind` — регистрация событий
- `event.get` — проверка регистрации
- `event.unbind` — удаление регистрации

### Проверка доступных методов обогащения

**Для обогащения нужны методы:**
- `crm.deal.get` — для сделок
- `crm.lead.get` — для лидов
- `crm.contact.get` — для контактов
- `crm.company.get` — для компаний
- `crm.item.get` — для смарт-процессов
- `tasks.task.get` — для задач
- `user.get` — для пользователей
- `sonet_group.get` — для проектов

### Проверка доступных справочников

**Для справочников нужны методы:**
- `crm.category.list` — категории
- `crm.status.list` — статусы/стадии
- `crm.type.list` — типы смарт-процессов

---

## Обработка ошибок

### Ошибка REST API

**Код:** `500`

**Формат:**
```json
{
  "error": "rest_error",
  "details": {
    "error": "invalid_token",
    "error_description": "..."
  }
}
```

**Логирование:** ошибка логируется в `logs/errors/error-YYYYMMDD.log`

### Неверный формат ответа

**Код:** `500`

**Формат:**
```json
{
  "error": "invalid_methods_response"
}
```

**Логирование:** ошибка логируется в `logs/errors/error-YYYYMMDD.log`

---

## Рекомендации

### Когда использовать

1. **При настройке модуля:**
   - Проверить доступность необходимых методов
   - Убедиться, что токен имеет нужные права

2. **При добавлении новых событий:**
   - Проверить доступность методов для обогащения
   - Проверить доступность методов для справочников

3. **При диагностике проблем:**
   - Проверить, не изменились ли доступные методы
   - Убедиться, что токен не потерял права

### Регулярная проверка

**Рекомендуется:**
- Запускать инструмент после изменения прав токена
- Сохранять результаты для сравнения
- Мониторить изменения в доступных методах

---

## Связанные документы

- `02-registered-events.md` — список регистрируемых событий
- `07-enrichment-services.md` — сервисы обогащения (маппинг методов)
- `17-configuration.md` — конфигурация модуля (настройка токена)

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием инструмента allowed-events.
