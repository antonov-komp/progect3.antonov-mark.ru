# ZERO-BUILD-REPORT (нулевая сборка)

**Дата:** 2026-01-15 23:07 (UTC+03:00, Brest)  
**Статус:** Zero build (as-is)  
**Глубина:** Полный обзор (backend + frontend + входные точки + инфраструктура)  

## Цель
Зафиксировать текущее состояние приложения как нулевую сборку и собрать 20 уточняющих вопросов по работе системы для кодревью и дальнейшего развития.

## Границы обзора
Включено:
- Backend PHP в `app/` и публичные точки входа в `public/`.
- Frontend Vue 3 в `frontend/src/`.
- Outgoing webhook модуль в `outgoing-webhook/`.
- Документация в `DOCS/` (архитектура, стек, модель данных, задачи).

Исключено:
- Реальное окружение Bitrix24 (права, webhook-ключи, сетевые настройки).
- Секреты и значения токенов (умышленно не фиксируются в документе).

## Архитектура (as-is)

### Входные точки
- `public/index.php` и `app/index.php` — точка входа UI (рендер контейнера `#app`, подключение `app.js/app.css`).
- `public/api/*.php` — прокси на `app/api/*.php`.

### Backend API (основные)
- `/api/ui-state.php` → `app/api/ui-state.php`
  - Формирует базовый UI-стейт (доступ, приветствие, контекст, данные пользователя).
- `/api/access-config.php` → `app/api/access-config.php`
  - Чтение/запись локального конфига доступа.
- `/api/access-directory.php` → `app/api/access-directory.php`
  - Справочники пользователей/отделов (доступно только супер-админу).

### Ключевые сервисы (app/Services)
- `RequestContextService` — фиксирует контекст запроса/сессии Bitrix24 (AUTH_ID/DOMAIN/PLACEMENT и др.).
- `AccessContextService` — определяет контекст доступа (embedded/direct/unknown) и auth-контекст.
- `AccessControlService` — решение доступа по конфигу и пользователю.
- `AccessConfigService` — чтение/запись `app/config/app-access.php`.
- `Bitrix24Client` — вызов REST API (через CRest или прямой token-контекст).
- `BitrixUserProfileService` — сбор профиля пользователя и его отдела.
- `UserGreetingService` + `GreetingComposerService` — формирование приветствия и сообщений контекста.
- `JsonResponseService` — стандартный JSON-ответ и ошибки.
- `AppLogger` — логирование событий и отказов доступа.
- `AccessDirectoryService` — справочники пользователей/отделов.

### Конфигурация доступа
Файл: `app/config/app-access.php`
- `global_enabled` — включает/выключает общий доступ по спискам.
- `deny_direct` — запрет прямого входа (вне Bitrix24).
- `super_admin_id` — ID супер-админа Bitrix24.
- `allowed_users`, `allowed_departments` — списки разрешенных сущностей.

### Интеграции Bitrix24
- REST API через `Bitrix24Client`:
  - режим CRest (через `app/crest.php` + `settings.php/settings.json`),
  - режим token-контекста (AUTH_ID + DOMAIN из запроса/SDK).
- Используемые методы: `user.current`, `user.get`, `user.admin`, `department.get`, `user.get` (каталог).

## Потоки данных (as-is)

### UI загрузка и контекст
1. Пользователь открывает `/index.php`.
2. HTML рендерит контейнер `#app`, подключает ассеты из `public/vue/`.
3. Если контекст embedded — подключается Bitrix24 SDK.
4. Frontend запрашивает `/api/ui-state.php`.

### Получение UI-стейта
1. `app/api/ui-state.php` формирует контекст доступа.
2. `AccessControlService` определяет, разрешен ли доступ.
3. Если доступ разрешен — `UserGreetingService` возвращает greeting/context/user data.
4. Ответ JSON отдается в UI.

### Управление доступом (только супер-админ)
1. UI открывает `AccessManager` → запрашивает `/api/access-config.php` и `/api/access-directory.php`.
2. При изменениях выполняется POST в `/api/access-config.php` (debounce 800ms).
3. Backend сохраняет `app/config/app-access.php`.

### Outgoing webhook
1. POST-запрос в `outgoing-webhook/index.php` (валидируется токен).
2. Payload сохраняется в `outgoing-webhook/logs/`.
3. Создается элемент очереди в `outgoing-webhook/queue/pending/`.

## Конфигурация и секреты
- `app/crest.php` использует `settings.php` и `settings.json` (конфиг Bitrix24).
- `outgoing-webhook/config.local.php` или ENV для `OUTGOING_WEBHOOK_TOKEN`.
- Секреты не должны храниться в коде; хранить только в окружении.

## Логирование и диагностика
- `AppLogger` пишет в `logs-apps/YYYY/MM/DD/app-open.log`.
- `JsonResponseService` и `Bitrix24Client` используют `AddMessage2Log` или `error_log`.
- Outgoing webhook пишет в `outgoing-webhook/logs/errors/` и `event.log`.

## Сборка фронтенда
- Исходники: `frontend/src/`.
- Сборка Vite: `npm run build` → `public/vue/app.js` и `public/vue/app.css`.

## Риски и неясности (as-is)
- Непрозрачно, где хранится `settings.php` и кто управляет `settings.json` (crest).
- Отсутствует документированная процедура регистрации приложения и выдачи прав Bitrix24.
- Не описан процесс обработки очереди `outgoing-webhook/queue/pending/`.
- Не зафиксирован набор required scopes Bitrix24 для REST-методов.

## 20 уточняющих вопросов по работе приложения
1. Какие сценарии считаются основными: embedded в Bitrix24 или direct-доступ по ссылке?
2. Должен ли direct-доступ вообще поддерживаться, или это временный режим?
3. Какой процесс установки приложения в Bitrix24: через marketplace, локальную установку, webhook?
4. Где и кем управляется `settings.php`/`settings.json` для CRest?
5. Какой набор прав (scopes) требуется для `user.current`, `user.get`, `department.get`, `user.admin`?
6. Какие ожидаемые форматы ошибок Bitrix24 нужно обрабатывать отдельно (например, expired_token)?
7. При ошибке загрузки профиля пользователя — допустимо ли показывать UI или нужно блокировать доступ?
8. Как определяется супер-админ: только по `super_admin_id` или возможны дополнительные правила?
9. Должен ли супер-админ обходить запрет `deny_direct`?
10. Что считать источником истины для контекста: параметры запроса, SDK `BX24.getAuth()` или сессия?
11. Нужна ли поддержка коробочной версии Bitrix24 (D7 ORM, `init.php`) в этом приложении?
12. Есть ли требование к хранению журнала доступа (ретеншн, ротация)?
13. Должны ли логи содержать персональные данные или требуется их маскирование?
14. Нужно ли кеширование справочника пользователей/отделов на стороне backend?
15. Какие ограничения по размеру списка пользователей/отделов ожидаются?
16. Какая стратегия обновления конфигурации: мгновенная запись или батч-обновления?
17. Нужно ли версионирование `app-access.php` или хранение истории изменений?
18. Как должен работать модуль `outgoing-webhook`: кто и когда читает `queue/pending/`?
19. Есть ли требование к idempotency обработчиков исходящих вебхуков?
20. Какие обязательные проверки нужно выполнить перед публикацией (security, тесты, статический анализ)?

## Что проделано в рамках нулевой сборки
- Проведен обзор архитектуры и структуры приложения (backend, frontend, webhook).
- Зафиксированы входные точки, ключевые сервисы и основной поток данных.
- Сформирован список рисков/неясностей и уточняющих вопросов (20 пунктов).

## История изменений
- 2026-01-15 23:07 (UTC+03:00, Brest): создан отчет нулевой сборки.
