# custom-inline-webhook

Дата: 2026-01-14 23:21 (UTC+3, Brest)

## Назначение
REST-приложение Bitrix24 с backend на PHP и frontend на Vue 3. UI работает через
JSON-эндпоинт, а интеграции с Bitrix24 выполняются только на backend.

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

## Логи
- `logs-apps/YYYY/MM/DD/app-open.log` — лог открытий приложения и контекста.

## Документация
- `DOCS/README.md` — навигация по документации.
- `DOCS/ARCHITECTURE/tech-stack.md` — актуальный стек.
- `DOCS/GUIDES/ui-standards.md` — стандарты UI (BX + Vue).

## Изменения
- 2026-01-14 23:21 (UTC+3, Brest): обновлено описание для Vue-версии.
