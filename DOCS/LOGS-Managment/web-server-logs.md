# Логи веб-сервера и PHP

## Где лежат

Каталог: `/var/www/progect3.antonov-mark.ru/logs/`.

Текущие файлы:

- `access.log`, `access-today.log` — журналы доступа.
- `error.log`, `error-today.log` — журналы ошибок веб-сервера.
- `php_error.log` — ошибки PHP.
- `archive/` — архивные логи.

Также встречаются файлы вида `access.log-YYYY-MM-DD` и `error.log-YYYY-MM-DD`.

## Назначение

- `access*.log` — запросы HTTP (статусы, пути, user-agent и т.п.).
- `error*.log` — ошибки уровня веб-сервера (непринятые запросы, прокси, таймауты).
- `php_error.log` — ошибки выполнения PHP (notice/warning/fatal).

## Когда использовать

- 5xx на клиенте — смотреть `error.log` и `php_error.log`.
- Нестабильность запросов — смотреть `access.log` и `error.log`.
- Для сравнения с прикладными логами — синхронизировать по времени.
