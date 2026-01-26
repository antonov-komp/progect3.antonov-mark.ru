# Конфигурация доступа к приложению

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0  
**Автор:** Технический писатель / Аналитик

---

## Оглавление

1. [Обзор](#обзор)
2. [Файл конфигурации](#файл-конфигурации)
3. [Параметры конфигурации](#параметры-конфигурации)
4. [Логика контроля доступа](#логика-контроля-доступа)
5. [Использование в приложении](#использование-в-приложении)
6. [Управление конфигурацией](#управление-конфигурацией)
7. [Примеры конфигураций](#примеры-конфигураций)

---

## Обзор

### Назначение

Файл `app/config/app-access.php` является **центральным конфигурационным файлом** для управления доступом к приложению. Он определяет:

- Глобальное включение/выключение доступа
- Разрешение/запрет прямого доступа (без встраивания в Bitrix24)
- Идентификатор супер-администратора
- Списки разрешённых пользователей и отделов

### Расположение

**Путь:** `/var/www/progect3.antonov-mark.ru/app/config/app-access.php`

**URL приложения:** `https://progect3.antonov-mark.ru/app/index.php`

> **Примечание:** Приложение работает на поддомене `progect3.antonov-mark.ru` и доступно через прямую ссылку внутри Bitrix24 в iframe.

---

## Файл конфигурации

### Структура файла

```php
<?php

return array (
  'global_enabled' => true,
  'deny_direct' => true,
  'super_admin_id' => '1619',
  'allowed_users' => 
  array (
  ),
  'allowed_departments' => 
  array (
  ),
);
```

### Формат

- Файл возвращает PHP-массив через `return`
- Используется `include` для загрузки конфигурации
- Автоматическая нормализация значений при чтении

---

## Параметры конфигурации

### `global_enabled` (bool)

**Назначение:** Глобальное включение/выключение доступа к приложению.

**Возможные значения:**
- `true` — доступ включен (по умолчанию)
- `false` — доступ полностью закрыт для всех пользователей, кроме супер-администратора

**Поведение:**
- Если `global_enabled = false`, доступ получает только супер-администратор
- Остальным пользователям показывается сообщение: "Супер Админ {имя} закрыл доступ в приложение."

**Пример:**
```php
'global_enabled' => true,  // Доступ открыт
'global_enabled' => false, // Доступ закрыт (только супер-админ)
```

### `deny_direct` (bool)

**Назначение:** Запрет прямого доступа к приложению (без встраивания в Bitrix24).

**Возможные значения:**
- `true` — прямой доступ запрещён (по умолчанию)
- `false` — прямой доступ разрешён

**Поведение:**
- Если `deny_direct = true`, приложение можно открыть только внутри Bitrix24 через iframe
- Если `deny_direct = false`, приложение можно открыть напрямую по URL (например, `https://progect3.antonov-mark.ru/app/index.php`)
- **Важно:** Запрет прямого доступа применяется ко всем пользователям, включая супер-администратора

**Контекст доступа:**
- `embedded` — приложение встроено в Bitrix24 (всегда разрешено, если `global_enabled = true`)
- `direct` — прямой доступ по URL (зависит от `deny_direct`, применяется ко всем)

**Пример:**
```php
'deny_direct' => true,  // Прямой доступ запрещён
'deny_direct' => false, // Прямой доступ разрешён
```

### `super_admin_id` (string)

**Назначение:** Идентификатор супер-администратора в Bitrix24.

**Формат:** Строка с числовым ID пользователя Bitrix24 (например, `'1619'`)

**Поведение:**
- Супер-администратор всегда имеет доступ к приложению (независимо от других настроек)
- Супер-администратор может управлять конфигурацией через API (`/api/access-config.php`)
- Если `super_admin_id` пустой, некоторые функции могут быть недоступны

**Пример:**
```php
'super_admin_id' => '1619',  // ID супер-администратора
'super_admin_id' => '',      // Супер-администратор не назначен
```

### `allowed_users` (array)

**Назначение:** Список ID пользователей Bitrix24, которым разрешён доступ к приложению.

**Формат:** Массив строк с ID пользователей

**Ограничения:**
- Максимум 200 элементов
- Дубликаты автоматически удаляются
- Пустые и невалидные значения игнорируются

**Поведение:**
- Если пользователь находится в списке `allowed_users`, ему разрешён доступ
- Проверка выполняется только для встроенного контекста (embedded)
- Для прямого доступа (direct) используется параметр `deny_direct`

**Пример:**
```php
'allowed_users' => [
  '123',
  '456',
  '789',
],
```

### `allowed_departments` (array)

**Назначение:** Список ID отделов Bitrix24, пользователям которых разрешён доступ к приложению.

**Формат:** Массив строк с ID отделов

**Ограничения:**
- Максимум 200 элементов
- Дубликаты автоматически удаляются
- Пустые и невалидные значения игнорируются

**Поведение:**
- Если пользователь принадлежит хотя бы к одному отделу из списка `allowed_departments`, ему разрешён доступ
- Проверка выполняется только для встроенного контекста (embedded)
- Для прямого доступа (direct) используется параметр `deny_direct`

**Пример:**
```php
'allowed_departments' => [
  '10',
  '20',
  '30',
],
```

---

## Логика контроля доступа

### Алгоритм принятия решения

Логика контроля доступа реализована в `AccessControlService::evaluateAccess()` и работает по следующему алгоритму:

```
1. Проверка ошибки конфигурации
   ├─► Если config_error = true → DENY (reason: 'config_error')
   └─► Иначе → Продолжить

2. Проверка супер-администратора
   ├─► Если userId === super_admin_id → ALLOW (reason: 'super_admin')
   └─► Иначе → Продолжить

3. Проверка глобального доступа
   ├─► Если global_enabled = false → DENY (reason: 'global_disabled')
   └─► Иначе → Продолжить

4. Проверка контекста доступа
   ├─► Если контекст = 'direct' или 'unknown'
   │   ├─► Если deny_direct = true → DENY (reason: 'deny_direct')
   │   └─► Если deny_direct = false → ALLOW (reason: 'direct_allowed')
   └─► Иначе (контекст = 'embedded') → Продолжить

5. Проверка списков доступа (только для embedded)
   ├─► Если userId в allowed_users → ALLOW (reason: 'allowed_list')
   ├─► Если departmentIds пересекаются с allowed_departments → ALLOW (reason: 'allowed_list')
   └─► Иначе → DENY (reason: 'not_allowed')
```

### Приоритет проверок

1. **Ошибка конфигурации** — наивысший приоритет (всегда запрещён)
2. **Глобальное отключение** — блокирует всех, кроме супер-администратора
3. **Запрет прямого доступа** — блокирует всех, включая супер-администратора (при `deny_direct = true` и прямом контексте)
4. **Супер-администратор** — разрешён для embedded контекста (не может обойти `deny_direct`)
5. **Списки доступа** — проверяются только для контекста `embedded`

### Сообщения об отказе в доступе

| Причина (`reason`) | Сообщение |
|-------------------|-----------|
| `config_error` | "Ошибка конфигурации доступа." |
| `deny_direct` | "Прямой доступ запрещен." |
| `not_allowed` | "Нет прав на доступ к приложению." |
| `global_disabled` | "Супер Админ {имя} закрыл доступ в приложение." |

---

## Использование в приложении

### Загрузка конфигурации

**Сервис:** `AccessConfigService`

```php
$configService = new AccessConfigService($appLogger);
$configResult = $configService->getConfig();

$config = $configResult['config'];
$configError = $configResult['config_error'];
```

**Метод:** `getConfig()`

**Возвращает:**
```php
[
    'config' => [
        'global_enabled' => true,
        'deny_direct' => true,
        'super_admin_id' => '1619',
        'allowed_users' => [],
        'allowed_departments' => [],
    ],
    'config_error' => false,
]
```

### Оценка доступа

**Сервис:** `AccessControlService`

```php
$accessControl = new AccessControlService(
    $accessContextService,
    $appLogger,
    $profileService,
    $configService
);

$accessDecision = $accessControl->evaluateAccess();
```

**Метод:** `evaluateAccess()`

**Возвращает:**
```php
[
    'allowed' => true,
    'message' => '',
    'decision' => 'allow',
    'reason' => 'super_admin',
    'context' => 'embedded',
    'is_embedded' => true,
    'is_super_admin' => true,
    'super_admin' => [...],
]
```

### Использование в API

**Эндпоинт:** `/api/ui-state.php`

```php
// Проверка доступа
$accessDecision = $accessControl->evaluateAccess();

if (!$accessDecision['allowed']) {
    $responseService->send([
        'allowed' => false,
        'deny_message' => $accessDecision['message'],
        // ...
    ]);
    return;
}

// Продолжение работы приложения
```

**Эндпоинт:** `/api/access-config.php`

```php
// Проверка прав супер-администратора
$isSuperAdmin = $currentUserId === $config['super_admin_id'];

if (!$isSuperAdmin) {
    $responseService->send([
        'status' => 'error',
        'error_message' => 'Нет прав на доступ к настройкам.',
    ]);
    return;
}

// Управление конфигурацией
```

---

## Управление конфигурацией

### Чтение конфигурации

**Через API (GET):**
```bash
GET /api/access-config.php?AUTH_ID=...&DOMAIN=...
```

**Ответ:**
```json
{
  "status": "ok",
  "config": {
    "global_enabled": true,
    "deny_direct": true,
    "super_admin_id": "1619",
    "allowed_users": [],
    "allowed_departments": []
  },
  "is_super_admin": true,
  "super_admin": {
    "id": "1619",
    "name": "Иван",
    "last_name": "Иванов"
  }
}
```

### Сохранение конфигурации

**Через API (POST):**
```bash
POST /api/access-config.php
Content-Type: application/json

{
  "global_enabled": true,
  "deny_direct": true,
  "allowed_users": ["123", "456"],
  "allowed_departments": ["10", "20"]
}
```

**Ограничения:**
- Только супер-администратор может изменять конфигурацию
- `super_admin_id` не может быть изменён через API
- Автоматическая нормализация значений при сохранении

### Нормализация значений

**Сервис:** `AccessConfigService::normalizeConfig()`

**Правила нормализации:**

1. **`global_enabled` и `deny_direct`:**
   - `true`, `1`, `'1'`, `'true'`, `'yes'`, `'y'` → `true`
   - Остальное → `false`

2. **`super_admin_id`:**
   - Число или строка с числом → строка (без ведущих нулей)
   - Пустое или невалидное → `''`

3. **`allowed_users` и `allowed_departments`:**
   - Массив → нормализованный массив уникальных ID
   - Не массив → `[]`
   - Максимум 200 элементов

---

## Примеры конфигураций

### Пример 1: Полный доступ для всех

```php
<?php

return array (
  'global_enabled' => true,
  'deny_direct' => false,
  'super_admin_id' => '1619',
  'allowed_users' => array (),
  'allowed_departments' => array (),
);
```

**Поведение:**
- Доступ открыт для всех пользователей
- Работает как внутри Bitrix24, так и напрямую по URL

### Пример 2: Только для встроенного контекста

```php
<?php

return array (
  'global_enabled' => true,
  'deny_direct' => true,
  'super_admin_id' => '1619',
  'allowed_users' => array (),
  'allowed_departments' => array (),
);
```

**Поведение:**
- Доступ только внутри Bitrix24 через iframe
- Прямой доступ по URL запрещён

### Пример 3: Доступ для конкретных пользователей

```php
<?php

return array (
  'global_enabled' => true,
  'deny_direct' => true,
  'super_admin_id' => '1619',
  'allowed_users' => array (
    '123',
    '456',
    '789',
  ),
  'allowed_departments' => array (),
);
```

**Поведение:**
- Доступ только для пользователей с ID 123, 456, 789
- Только внутри Bitrix24 через iframe

### Пример 4: Доступ для отделов

```php
<?php

return array (
  'global_enabled' => true,
  'deny_direct' => true,
  'super_admin_id' => '1619',
  'allowed_users' => array (),
  'allowed_departments' => array (
    '10',
    '20',
  ),
);
```

**Поведение:**
- Доступ для всех пользователей отделов 10 и 20
- Только внутри Bitrix24 через iframe

### Пример 5: Полное закрытие доступа

```php
<?php

return array (
  'global_enabled' => false,
  'deny_direct' => true,
  'super_admin_id' => '1619',
  'allowed_users' => array (),
  'allowed_departments' => array (),
);
```

**Поведение:**
- Доступ закрыт для всех, кроме супер-администратора (ID 1619)
- Остальным показывается сообщение об отказе

---

## Интеграция с Bitrix24

### Контекст доступа

Приложение определяет контекст доступа через `AccessContextService`:

- **`embedded`** — приложение встроено в Bitrix24 через iframe
- **`direct`** — прямой доступ по URL (например, `https://progect3.antonov-mark.ru/app/index.php`)
- **`unknown`** — контекст не определён

### Параметры от Bitrix24

При встраивании в Bitrix24 передаются параметры:
- `AUTH_ID` — токен авторизации
- `DOMAIN` — домен портала
- `IFRAME=1` или `B24_FRAME=1` — признак встраивания

### URL приложения в Bitrix24

```
https://progect3.antonov-mark.ru/app/index.php?
  AUTH_ID=abc123xyz&
  DOMAIN=your-portal.bitrix24.ru&
  IFRAME=1&
  B24_FRAME=1
```

---

## Логирование

### События доступа

Все попытки доступа логируются через `AppLogger`:

**Канал:** `app-open`

**Структура лога:**
```json
{
  "user_id": "***19",
  "name": "И***",
  "last_name": "И***",
  "portal_id": "your-portal.bitrix24.ru",
  "is_admin": "unknown",
  "admin_source": "access_control",
  "access": "embedded",
  "department": "Отдел продаж",
  "status": "deny",
  "message": "Нет прав на доступ к приложению.",
  "access_context": "embedded",
  "access_decision": "deny",
  "access_reason": "not_allowed"
}
```

**Маскирование данных:**
- `user_id` — маскируется (оставляются последние 2 символа)
- `name` и `last_name` — маскируются (оставляется первая буква)

---

## Связанные документы

- [Интеграция с Bitrix24 через iframe](./bitrix24-iframe-integration.md) — описание работы приложения внутри Bitrix24
- [API приложения](../API-REFERENCES/app-api.md) — документация по API эндпоинтам
- [Архитектура технологического стека](../ARCHITECTURE/tech-stack.md) — общая архитектура приложения

---

## Заключение

Файл `app/config/app-access.php` является ключевым компонентом системы контроля доступа приложения. Он позволяет:

1. **Гибко управлять доступом** через настройки конфигурации
2. **Ограничивать доступ** по пользователям и отделам
3. **Контролировать прямой доступ** к приложению
4. **Назначать супер-администратора** для управления системой

Все изменения конфигурации автоматически нормализуются и сохраняются в файл, обеспечивая надёжную работу системы контроля доступа.
