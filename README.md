# custom-inline-webhook

Дата: 2026-01-21 00:00 (UTC+3, Брест)

## Назначение
REST‑приложение Bitrix24 с backend на PHP и frontend на Vue 3. UI работает через
JSON‑эндпоинт, а интеграции с Bitrix24 выполняются только на backend. Приложение
включает контроль доступа (внутри Bitrix24 и при прямом доступе) и отдельный
модуль приёма исходящих вебхуков Bitrix24.

## Быстрый старт
1. Настройте веб-сервер так, чтобы `public/` был корнем сайта.
2. Откройте `/index.php` (или перейдите на `/` — будет редирект).
3. Соберите фронтенд, если ассеты отсутствуют.

## Сборка фронтенда
```
cd frontend
npm install
npm run build
```
Результат: `public/vue/app.js` и `public/vue/app.css`.

## Основные эндпоинты
- `/index.php` — точка входа UI (Vue).
- `/api/ui-state.php` — JSON-эндпоинт UI (прокси на `app/api/ui-state.php`).
- `/api/access-config.php` — чтение/запись локального конфига доступа.
- `/api/access-directory.php` — справочники пользователей и отделов (только супер‑админ).
- `/outgoing-webhook/index.php` — приём исходящих вебхуков Bitrix24 (POST, token).

## Контроль доступа
- Контекст доступа: `embedded` (внутри Bitrix24) и `direct` (прямой доступ), возможен `unknown`.
- Конфиг доступа: `app/config/app-access.php` (`global_enabled`, `deny_direct`,
  `super_admin_id`, `allowed_users`, `allowed_departments`).
- Решение доступа применяется при формировании UI‑состояния в `/api/ui-state.php`.

## Исходящие вебхуки Bitrix24
- Раздел: `outgoing-webhook/`.
- Валидация токена через `OUTGOING_WEBHOOK_TOKEN` (ENV или `outgoing-webhook/config.local.php`).
- Логирование входящих событий в `outgoing-webhook/logs/`.
- Очередь JSON‑файлов в `outgoing-webhook/queue/pending/` для последующей обработки.

## Логи
- `logs-apps/YYYY/MM/DD/app-open.log` — лог открытий приложения и контекста.
- `outgoing-webhook/logs/errors/` — ошибки при приёме вебхуков.
- `outgoing-webhook/logs/<EVENT>/event.log` — журнал по типам событий.

## Документация
- `DOCS/README.md` — навигация по документации.
- `DOCS/ARCHITECTURE/tech-stack.md` — актуальный стек.
- `DOCS/GUIDES/ui-standards.md` — стандарты UI (BX + Vue).
- `DOCS/FIRST_v1/ZERO-BUILD-REPORT.md` — отчёт нулевой сборки.

## Изменения
- 2026-01-14 23:21 (UTC+3, Brest): обновлено описание для Vue‑версии.
- 2026-01-21 00:00 (UTC+3, Брест): актуализировано под текущее состояние приложения.
