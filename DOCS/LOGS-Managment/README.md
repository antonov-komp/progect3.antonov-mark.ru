# Логирование в проекте

Документ описывает, как устроено логирование в REST-приложении и где находятся все каталоги с логами.

## Карта каталогов логов

- `/var/www/progect3.antonov-mark.ru/logs/` — логи веб-сервера и PHP (доступ/ошибки/архивы).
- `/var/www/progect3.antonov-mark.ru/logs-apps/` — прикладные логи приложения (каналы AppLogger).
- `/var/www/progect3.antonov-mark.ru/app/logs/` — логи REST-запросов Bitrix24 (CRest::setLog).
- `/var/www/progect3.antonov-mark.ru/outgoing-webhook/logs/` — логи входящих событий (outgoing webhook) и их обработки.
- `/var/www/progect3.antonov-mark.ru/.git/logs/` — служебные логи Git (внутренние, не относятся к приложению).
- `/var/www/progect3.antonov-mark.ru/.npm-cache/_logs/` — служебные логи npm (внутренние, не относятся к приложению).

## Быстрые ссылки

- Прикладные логи приложения: `app-logs.md`
- Логи Bitrix24 REST (CRest): `bitrix24-rest-logs.md`
- Логи outgoing webhook: `outgoing-webhook-logs.md`
- Логи веб-сервера и PHP: `web-server-logs.md`
- Прочие служебные логи: `other-logs.md`

## Общие принципы

- Таймзона в логах приложения: `Europe/Minsk` (UTC+3, Brest).
- Форматы логов отличаются по подсистемам: текстовые строки, JSON, line-delimited JSON.
- Секреты и токены не должны попадать в логи. В outgoing webhook часть данных маскируется.

## Где искать ошибку

- Ошибка REST-запроса к Bitrix24: `app/logs/YYYY-MM-DD/HH/*_callCurl_*log.json`.
- Ошибка входящего webhook: `outgoing-webhook/logs/errors/error-YYYYMMDD.log`.
- Ошибка доступа пользователя: `logs-apps/YYYY/MM/DD/app-open.log`.
- Ошибки PHP: `logs/php_error.log` и `logs/error.log`.
