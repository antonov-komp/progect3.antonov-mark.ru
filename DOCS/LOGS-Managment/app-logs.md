# Прикладные логи приложения (AppLogger)

## Источник и код

- Класс логирования: `app/Services/AppLogger.php`.
- Точка записи: `AppLogger::log($channel, $context)`.
- Формирование путей: `/logs-apps/YYYY/MM/DD/<channel>.log`.

## Где лежат

- Базовый каталог: `/var/www/progect3.antonov-mark.ru/logs-apps/`.
- Иерархия: `YYYY/MM/DD/<channel>.log`.
- Пример: `logs-apps/2026/01/21/app-open.log`.

## Каналы (channel)

- `app-open` — события открытия приложения и решения по доступу.
- `access-config` — ошибки чтения/записи `app/config/app-access.php`.
- `access-modules` — ошибки чтения/записи `app/config/app-modules-access.php`.
- `access-directory` — ошибки загрузки справочников (users, departments) и кеша.

## Формат строки лога

Логи пишутся одной строкой, разделители — ` | `, формат ключ=значение:

```
2026-01-21 11:16 (UTC+3, Brest) | user_id=1619 | name=*** | last_name=*** | portal_id=*** | is_admin=yes | admin_source=user.admin | access=embedded | department=*** | status=ok | message=user.current ok | access_mode=unknown | access_context=embedded | access_decision=allow | access_reason=super_admin | auth_source=request_token | auth_domain=***
```

## Набор ключей и порядок

Порядок ключей фиксирован в `AppLogger::formatLogLine()`:

- `user_id`, `name`, `last_name`, `portal_id`, `is_admin`, `admin_source`, `access`, `department`,
- `status`, `message`, `access_mode`, `access_context`, `access_decision`, `access_reason`,
- `auth_source`, `auth_domain`.

Остальные поля, если переданы, добавляются в конец строки.

## Маскирование данных

- В `AccessControlService` для отказов маскируются `user_id`, `name`, `last_name`.
- При успешном доступе значения могут быть полными (см. `UserGreetingService`).

## Когда пишется

- `UserGreetingService::logOpen()` — запись открытия приложения и состояния доступа.
- `AccessControlService::logAccessDecision()` — запись при отказе доступа.
- `AccessConfigService`, `AccessModulesService`, `AccessDirectoryService` — запись ошибок конфигураций/справочников.

## Рекомендации по диагностике

- Проверять `app-open.log` при проблемах доступа пользователей.
- Проверять `access-config.log` и `access-modules.log` при сбоях чтения/записи конфигов.
- Проверять `access-directory.log` при проблемах со справочниками или кешем.
