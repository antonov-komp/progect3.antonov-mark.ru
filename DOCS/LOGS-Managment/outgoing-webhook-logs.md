# Логи outgoing webhook

## Источник и код

- Входная точка: `outgoing-webhook/index.php`.
- Общие функции: `outgoing-webhook/bootstrap.php`.
- Обработчик очереди: `outgoing-webhook/tools/process-queue.php`.
- Формирование списка разрешенных событий: `outgoing-webhook/tools/allowed-events.php`.

## Где лежат

- Базовый каталог: `/var/www/progect3.antonov-mark.ru/outgoing-webhook/logs/`.

Структура каталогов:

- `<EVENT_TYPE>/event.log` — line-based лог событий.
- `<EVENT_TYPE>/raw.json` — последний принятый сырой payload (с маскированием токена).
- `<EVENT_TYPE>/enriched.json` — обогащенные данные после обработки очереди.
- `errors/error-YYYYMMDD.log` — line-based JSON ошибок.
- `allowed-events/allowed-events.json` — сгруппированные REST-методы (генерируется утилитой).
- `allowed-events/raw-methods.json` — полный список методов Bitrix24.
- `dicts/*.json` — кеш справочников (статусы/категории/типы).
- `state/*.json` — снимки состояния сущностей для сравнения изменений.
- `field-changes/*.json` и `field-changes/field-changes.log` — изменения полей сущностей.

## Форматы

### event.log

Одна строка на событие:

```
2026-01-21T11:00:00+03:00 | IP=*** | event=ONCRMDEALUPDATE | entityType=deal | entityId=123
```

### raw.json

Содержит `payload` с маскированием токенов (ключи `token`, `auth.application_token`, `auth.app_token`).

### enriched.json

Обогащенные данные через REST (например `crm.deal.get`) + справочники.

### errors/error-YYYYMMDD.log

Line-based JSON (одна запись — одна строка).

## Логика записи

1. `index.php` валидирует запрос, токен и размер.
2. Записывает:
   - `<EVENT_TYPE>/raw.json`
   - `<EVENT_TYPE>/event.log`
3. Создает элемент очереди в `outgoing-webhook/queue/pending/`.
4. `tools/process-queue.php`:
   - обогащает данные через REST,
   - пишет `<EVENT_TYPE>/enriched.json`,
   - сохраняет state и диффы полей.

## Ошибки

- Все ошибки идут в `logs/errors/error-YYYYMMDD.log` + `error_log`.
- Примеры причин: неверный токен, ошибка REST, недоступные каталоги.

## Безопасность

- Токены маскируются (видны только последние 4 символа).
- Для токена используется `OUTGOING_WEBHOOK_TOKEN` (из env или `outgoing-webhook/config.local.php`).
