# Почему нет событий после определённой даты

**Дата:** 2026-02-16

## Симптом

В БД модуля исходящих событий последние записи — от 13.02.2026 (например 19:00:50). Создание задач и другие действия 14–16 февраля в приложении не отображаются.

## Диагноз

**После 13.02 запросы от Bitrix24 на сервер приложения не доходят.**

Подтверждение:

1. **Логи.** В `outgoing-webhook/logs/errors/` есть файлы только до `error-20260213.log`. Файлов `error-20260214.log`, `error-20260215.log`, `error-20260216.log` нет. Каждый входящий запрос к `index.php` пишет запись в лог — значит, с 14 февраля запросов не было.

2. **БД.** В таблице `events` последние строки по полю `received_at` — 2026-02-13 (время около 19:00). Новых событий за 14–16 число нет.

3. **Проверка подписок.** Скрипт `outgoing-webhook/tools/check-event-bindings.php` (вызов `event.get` в Bitrix24) на момент проверки показал: зарегистрированных обработчиков событий нет. То есть либо исходящий вебхук отвязан/удалён, либо подписки не видны через этот метод (если вебхук создан только через интерфейс).

## Возможные причины

| Причина | Что проверить |
|--------|----------------|
| Исходящий вебхук удалён или отключён | В Bitrix24: Разработчикам → Другое → Исходящий вебхук (или настройки приложения). Есть ли нужный обработчик, включён ли он. |
| Указан неверный URL | URL должен быть: `https://progect3.antonov-mark.ru/outgoing-webhook/index.php` (без `/public/` в пути). Проверить в настройках вебхука. |
| Подписаны не те события | Для задач нужны: `ONTASKADD`, `ONTASKUPDATE`, `ONTASKDELETE`, `ONTASKCOMMENTADD`. Для сделок: `ONCRMDEALADD`, `ONCRMDEALUPDATE`. В настройках вебхука должны быть отмечены нужные события. |
| Неверный токен | В настройках исходящего вебхука в Bitrix24 должен быть тот же токен, что и `OUTGOING_WEBHOOK_TOKEN` в `outgoing-webhook/config.local.php`. |
| Сеть / доступность | С серверов Bitrix24 должен открываться ваш домен по HTTPS. Проверить с другого сервера: `curl -I https://progect3.antonov-mark.ru/outgoing-webhook/index.php`. |

## Что сделать

1. **Проверить подписки (если есть входящий вебхук с правами event):**
   ```bash
   php outgoing-webhook/tools/check-event-bindings.php
   ```
   Или в браузере: `https://progect3.antonov-mark.ru/outgoing-webhook/tools/check-event-bindings.php`

2. **В Bitrix24 проверить исходящий вебхук:**
   - Разработчикам → Другое → Исходящий вебхук (или через настройки приложения).
   - Убедиться, что есть обработчик с URL:  
     `https://progect3.antonov-mark.ru/outgoing-webhook/index.php`
   - Токен должен совпадать с `OUTGOING_WEBHOOK_TOKEN` из `outgoing-webhook/config.local.php`.
   - В списке событий должны быть нужные: минимум `ONTASKADD`, `ONTASKUPDATE`, `ONCRMDEALUPDATE` (и другие по необходимости).

3. **При необходимости создать вебхук заново:** указать URL и токен выше, подписать события (задачи, сделки и т.д.), сохранить.

4. **Проверить приход событий:** создать тестовую задачу в Bitrix24, затем посмотреть:
   - `php outgoing-webhook/tools/check-events-in-db.php`
   - или страницу: `https://progect3.antonov-mark.ru/outgoing-webhook/tools/check-events-status.php`

## Проверка доступности endpoint с вашего ПК

Выполните с компьютера (или с сервера, откуда есть доступ в интернет):

```bash
curl -s -w "\nHTTP_CODE:%{http_code}\n" -X POST \
  -H "Content-Type: application/json" \
  -d '{"event":"ONTASKADD","token":"ВАШ_ТОКЕН_ИЗ_config_local","data":{"FIELDS":{"ID":"1"}}}' \
  https://progect3.antonov-mark.ru/outgoing-webhook/index.php
```

Подставьте вместо `ВАШ_ТОКЕН_ИЗ_config_local` значение `OUTGOING_WEBHOOK_TOKEN` из `outgoing-webhook/config.local.php`.

- Ответ `{"status":"ok"}` и HTTP 200 — endpoint доступен, токен принят. Тогда создайте задачу в B24 и снова проверьте БД; если событий нет — в B24 указан другой URL или не подписаны события.
- HTTP 403 — неверный токен (в B24 и в config должен быть один и тот же).
- Таймаут / connection refused — домен снаружи недоступен (firewall, nginx, SSL).

После успешного curl проверьте: `php outgoing-webhook/tools/check-events-in-db.php` — должно появиться новое событие.

## Итог

События за 16 число (и за 14–15) не видны, потому что **Bitrix24 с 14 февраля больше не отправляет запросы на ваш сервер** (или они не доходят). Нужно в Bitrix24 проверить/настроить исходящий вебхук: URL, токен и список событий (в т.ч. `ONTASKADD`, `ONTASKUPDATE` для задач).
