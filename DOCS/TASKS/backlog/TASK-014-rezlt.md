# TASK-014: Результат выполнения

Дата: 2026-01-15 17:08 (UTC+3, Brest)
Статус: done
Приоритет: high
Исполнитель: Backend PHP (Bitrix24 REST)

## Краткий итог
- Создан раздел `/outgoing-webhook/` с endpoint, логами и файловой очередью.
- Реализован приём webhook-событий с валидацией токена и маскированием секрета.
- Добавлены инструменты: анализ доступных методов и обработчик очереди обогащения.
- Реализовано асинхронное обогащение с кэшем справочников.

## Реализованные компоненты
### Раздел `/outgoing-webhook/`
- `outgoing-webhook/index.php` — endpoint приёма событий (POST).
- `outgoing-webhook/bootstrap.php` — вспомогательные функции (валидация, логирование, маскирование).
- `outgoing-webhook/tools/allowed-events.php` — REST-анализ доступных методов (methods).
- `outgoing-webhook/tools/process-queue.php` — обработчик файловой очереди обогащения.
- Логи и очередь:
  - `outgoing-webhook/logs/`
  - `outgoing-webhook/queue/`

## Формат логов и очереди
- `logs/<eventType>/raw.json` — сырой payload с маскированием токена.
- `logs/<eventType>/event.log` — краткая строка события.
- `logs/<eventType>/enriched.json` — обогащённый payload.
- `queue/pending/*.json` — задания на обогащение.
- `queue/processing/`, `queue/done/`, `queue/failed/` — стадии обработки.

## Настройки окружения
Обязательные переменные (секреты не хранить в коде):
- `OUTGOING_WEBHOOK_TOKEN` — ожидаемый токен webhook.
- `BITRIX24_WEBHOOK_URL` — REST webhook URL Bitrix24.

## Контракты и ограничения
- Принимается только POST.
- Максимальный размер payload: 2 MB.
- Token маскируется при записи в `raw.json`.
- Ошибки пишутся в `logs/errors/error-YYYYMMDD.log`.
- Обогащение идёт асинхронно через очередь, без Redis.

## Карта «event → метод обогащения»
- `ONCRMDEAL*` → `crm.deal.get`
- `ONCRMLEAD*` → `crm.lead.get`
- `ONCRMCONTACT*` → `crm.contact.get`
- `ONCRMCOMPANY*` → `crm.company.get`
- `ONCRMITEM*` → `crm.item.get`
- `ONTASK*` → `tasks.task.get`
- `ONUSER*` → `user.get`
- `SONET_GROUP_*` → `sonet_group.get`
- `ONCRMUSERFIELD*` → `crm.userfield.list`

## Проверка работоспособности (manual)
1. Endpoint webhook:
```
curl -X POST -H "Content-Type: application/json" \
  -d '{"token":"YOUR_TOKEN","event":"ONCRMDEALADD","data":{"FIELDS":{"ID":"123"}}}' \
  https://<host>/outgoing-webhook/index.php
```
2. Анализ доступных методов:
```
https://<host>/outgoing-webhook/tools/allowed-events.php
```
3. Обработка очереди:
```
https://<host>/outgoing-webhook/tools/process-queue.php?limit=10
```

## Что требуется дополнительно (manual steps)
- Зарегистрировать outgoing webhook на URL `/outgoing-webhook/index.php`.
- Настроить cron на `tools/process-queue.php` (например, раз в минуту).

## Изменённые/добавленные файлы
- `outgoing-webhook/bootstrap.php`
- `outgoing-webhook/index.php`
- `outgoing-webhook/tools/allowed-events.php`
- `outgoing-webhook/tools/process-queue.php`
