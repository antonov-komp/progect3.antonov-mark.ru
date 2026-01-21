# API приложения

Дата: 2026-01-14 23:21 (UTC+3, Брест)
Обновлено: 2026-01-14 23:29 (UTC+3, Брест)

## Назначение
Документ описывает backend-эндпоинты, которые обслуживают Vue UI. Все интеграции
с Bitrix24 выполняются на backend, фронтенд получает только JSON.

## Эндпоинты

### `GET /api/ui-state.php`
Прокси-скрипт, который передаёт запрос в `app/api/ui-state.php`.

**Назначение:** получить состояние UI, включая доступ, приветствие и контекст.

**Источник данных:**
- `UserGreetingService` (Bitrix24 REST: `user.current`, `user.get`, `user.admin`, `department.get`)
- `AccessControlService` (режим доступа из `app/config/app-access.php`)

**Параметры (query):**
- `AUTH_ID` — токен Bitrix24 (если доступен через `BX24.getAuth()`).
- `DOMAIN` — домен портала Bitrix24.
- `member_id` — ID портала (если доступен).
- `PLACEMENT`, `PLACEMENT_OPTIONS`, `IFRAME`, `B24_FRAME` — признаки embedded-контекста.

**Допустимые значения:**
- `AUTH_ID` — строка, token Bitrix24 (`access_token`).
- `DOMAIN` — строка, домен портала (без схемы).
- `member_id` — строка, ID портала (например, `b24_abcd1234`).
- `IFRAME`, `B24_FRAME` — `1` или `true` (строкой).

**Особенности обработки параметров:**
- Значения из `window.APP_REQUEST_CONTEXT` объединяются с результатом `BX24.getAuth()`.
- Параметры добавляются в query только если непустые.
- Backend дополнительно читает `$_SESSION` для восстановления контекста.

**Ответ (JSON):**
- `allowed` (bool) — доступ разрешён или запрещён.
- `deny_message` (string) — сообщение при запрете.
- `greeting` (string) — приветствие пользователя.
- `context_message` (string) — строка контекста (админ/контекст/отдел).
- `access` (object):
  - `context` (string): `embedded` | `direct` | `unknown`.
  - `is_embedded` (bool).
  - `mode` (string): `allow_all` | `deny_all` | `deny_direct` | `deny_embedded` | `unknown`.
  - `decision` (string): `allow` | `deny`.
  - `reason` (string): `allow` | `deny_all` | `deny_direct` | `deny_embedded` | `invalid_mode` | `config_error` | `unknown`.
- `auth` (object):
  - `source` (string): `app_token` | `request_token`.
- `user` (object):
  - `name` (string).
  - `last_name` (string).
  - `is_admin` (bool|null).
  - `department` (string).
- `status` (string): `ok` | `error`.
- `error_message` (string).

**Гарантии контракта:**
- Все ключи присутствуют всегда (при запрете и при ошибках).
- Ошибки возвращаются в нейтральной форме без технических деталей.

**Коды ответа:**
- `200` — нормальный ответ, даже при `status=error`.
- `4xx/5xx` — только при ошибках веб-сервера, на уровне PHP не выбрасывается.

**Логирование:**
- Ошибки Bitrix24 логируются через `AddMessage2Log` (если доступен) или `error_log`.
- Открытия приложения фиксируются в `logs-apps/YYYY/MM/DD/app-open.log`.

**Пример успешного ответа:**
```
{
  "allowed": true,
  "deny_message": "Приложение закрыто для входа Администратором",
  "greeting": "Привет, Иван Иванов!",
  "context_message": "Администратор портала: да; контекст: внутри Bitrix24; отдел: Продажи.",
  "access": {
    "context": "embedded",
    "is_embedded": true,
    "mode": "deny_embedded",
    "decision": "allow",
    "reason": "allow"
  },
  "auth": {
    "source": "request_token"
  },
  "user": {
    "name": "Иван",
    "last_name": "Иванов",
    "is_admin": true,
    "department": "Продажи"
  },
  "status": "ok",
  "error_message": ""
}
```

**Пример запрета доступа:**
**Пример ошибки Bitrix24:**
```
{
  "allowed": true,
  "deny_message": "Приложение закрыто для входа Администратором",
  "greeting": "Привет!",
  "context_message": "",
  "access": {
    "context": "embedded",
    "is_embedded": true,
    "mode": "allow_all",
    "decision": "allow",
    "reason": "allow"
  },
  "auth": {
    "source": "request_token"
  },
  "user": {
    "name": "",
    "last_name": "",
    "is_admin": null,
    "department": ""
  },
  "status": "error",
  "error_message": "Не удалось загрузить данные приложения."
}
```

## Поток запроса
1. `app/index.php` собирает `APP_REQUEST_CONTEXT` из `$_REQUEST` и `$_SESSION`.
2. `frontend/src/services/apiClient.js` запрашивает `/api/ui-state.php` и добавляет auth из `BX24.getAuth()`.
3. `app/api/ui-state.php`:
   - проверяет доступ через `AccessControlService`;
   - при `allowed=false` сразу возвращает JSON;
   - иначе вызывает `UserGreetingService` для получения пользователя и контекста.

## Правила доступа (backend)
Источник: `app/config/app-access.php`

Режимы:
- `allow_all` — доступ разрешён везде.
- `deny_all` — полный запрет.
- `deny_direct` — запрет при `context=direct`.
- `deny_embedded` — запрет при `context=embedded`.
- Неизвестный режим → `deny_all` + `reason=invalid_mode`.

## Безопасность
- Секреты не возвращаются в JSON.
- Все тексты для UI экранируются на backend перед выводом (в HTML).
- Значения auth не логируются.

## Тестирование
- embedded/direct контекст.
- все режимы доступа (`allow_all`, `deny_all`, `deny_direct`, `deny_embedded`).
- администратор/обычный пользователь.
- ошибка Bitrix24 (`status=error`), UI показывает нейтральное сообщение.
```
{
  "allowed": false,
  "deny_message": "Приложение закрыто для входа Администратором",
  "greeting": "",
  "context_message": "",
  "access": {
    "context": "direct",
    "is_embedded": false,
    "mode": "deny_direct",
    "decision": "deny",
    "reason": "deny_direct"
  },
  "auth": {
    "source": "app_token"
  },
  "user": {
    "name": "",
    "last_name": "",
    "is_admin": null,
    "department": ""
  },
  "status": "ok",
  "error_message": ""
}
```

## Связанные компоненты
- Backend: `app/api/ui-state.php`, `app/Services/UserGreetingService.php`.
- Frontend: `frontend/src/services/apiClient.js`, `frontend/src/composables/useUiState.js`.

## Связанные API Bitrix24
- `user.current` — https://context7.com/bitrix24/rest/user.current
- `user.get` — https://context7.com/bitrix24/rest/user.get
- `user.admin` — https://context7.com/bitrix24/rest/user.admin
- `department.get` — https://context7.com/bitrix24/rest/department.get

## Изменения
- 2026-01-14 23:21 (UTC+3, Брест): создана документация по UI-эндпоинтам.
- 2026-01-14 23:29 (UTC+3, Брест): добавлены схемы, поток запроса и правила доступа.
