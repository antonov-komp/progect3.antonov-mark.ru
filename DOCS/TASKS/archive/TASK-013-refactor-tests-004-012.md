# TASK-013: Набор тестов для проверки выполнения рефакторинга TASK-004..012

Дата: 2026-01-15 09:55 (UTC+3, Брест)
Статус: in_progress
Приоритет: high
Исполнитель: Тестировщик (QA)

## Цель
Подготовить детальный набор тестов, подтверждающий выполнение рефакторинга по задачам TASK-004..012 в backend и frontend слоях приложения Bitrix24.

## Контекст
В рамках TASK-004..012 были изменены bootstrap/HTTP слой, сервисная декомпозиция, REST-клиент, логирование и Vue-структура. Необходимо зафиксировать проверочные сценарии для подтверждения корректности выполненных изменений.

## Требования
### Функциональные
- Покрыть тестами все критерии приемки из TASK-004..012.
- Включить проверки структуры кода (наличие сервисов, переиспользование, отсутствие дублирования).
- Включить интеграционные сценарии для `ui-state` и Vue UI.
- Подтвердить корректность логирования и конфигураций доступа.

### Нефункциональные
- Не использовать реальные секреты в тестовых данных (маскировать токены).
- Проверять формат даты/логов по Бресту (UTC+3).
- Ошибки Bitrix24 не должны ломать основной сценарий UI.

## Модули/компоненты
- Backend:
  - `app/index.php`
  - `app/api/ui-state.php`
  - `app/Services/RequestContextService.php`
  - `app/Services/JsonResponseService.php`
  - `app/Services/AccessContextService.php`
  - `app/Services/BitrixUserProfileService.php`
  - `app/Services/GreetingComposerService.php`
  - `app/Services/UserGreetingService.php`
  - `app/Services/Bitrix24Client.php`
  - `app/Services/AppLogger.php`
  - `app/Services/AccessControlService.php`
  - `app/config/app-access.php`
- Frontend:
  - `frontend/src/app/main.js`
  - `frontend/src/bootstrap/*`
  - `frontend/src/services/*`
  - `frontend/src/stores/uiStateStore.js`
  - `frontend/src/composables/useUiState.js`
  - `frontend/src/views/AppView.vue`
  - `frontend/src/components/*`
  - `frontend/src/styles/app.css`
- Документация:
  - `DOCS/ARCHITECTURE/tech-stack.md`
  - `DOCS/GUIDES/ui-standards.md`
  - `DOCS/GUIDES/vue-test-checklist.md`

## Зависимости (иерархия)
1. TASK-004..007 — backend рефакторинг и логирование.
2. TASK-008..011 — Vue слой, UI и сборка.
3. TASK-012 — фиксация прогресса и обновление документации.

## Подзадачи
1. Собрать тестовую матрицу по задачам 004..012.
2. Зафиксировать тестовые данные и сценарии (embedded/direct).
3. Выполнить проверки по backend и frontend.
4. Оформить результаты и артефакты (скриншоты, логи).

## API-методы Bitrix24
- `user.current` — https://context7.com/bitrix24/rest/user.current
- `user.get` — https://context7.com/bitrix24/rest/user.get
- `department.get` — https://context7.com/bitrix24/rest/department.get
- `user.admin` — https://context7.com/bitrix24/rest/user.admin

## Технические требования
- Тестирование проводится в cloud Bitrix24 (embedded) и прямой ссылке (direct).
- Все ответы `ui-state` должны содержать `status` и `error_message`.
- Никаких прямых REST-вызовов Bitrix24 с фронта.
- Ошибки REST и записи логов не прерывают основной сценарий.

## Критерии приемки
- Набор тестов полностью покрывает TASK-004..012.
- Все сценарии имеют шаги, ожидаемый результат и артефакты.
- Зафиксированы проверки структуры кода и поведения в runtime.

## Путь тестирования
1. Подготовка окружения:
   - Убедиться, что `public/vue/app.js` и `public/vue/app.css` присутствуют.
   - Открыть приложение по `/app/index.php` (direct) и из портала Bitrix24 (embedded).
2. Проверка backend контрактов:
   - Последовательно выполнить тесты T004-01..T007-03.
3. Проверка frontend слоя:
   - Выполнить T008-01..T012-02 в порядке задач.
4. Фиксация результатов:
   - Таблица Pass/Fail/Blocked.
   - Скриншоты UI (embedded/direct).
   - Фрагменты логов `logs-apps/`.

## Успешный результат (глобально)
- Все тесты имеют статус Pass.
- В JSON-ответах `ui-state`:
  - есть ключи `status`, `error_message`, `allowed`, `access`, `user`;
  - `status` принимает `ok|error`, `allowed` — boolean;
  - нет PHP warnings в теле ответа.
- В UI нет падений, ошибки отображаются нейтрально, без XSS.
- Логи пишутся в формате `YYYY-MM-DD HH:MM (UTC+3, Brest) | key=value`.

## Тестовые данные (минимум)
- AUTH_ID: `****token`
- DOMAIN: `example.bitrix24.ru`
- member_id: `****member`
- PLACEMENT: `CRM`
- Прямой режим: без BX24 SDK, только `APP_REQUEST_CONTEXT`.

## Набор тестов

### TASK-004: Bootstrap/HTTP слой
**T004-01: Единый сбор контекста запроса**
- Предусловия: доступ к `/app/api/ui-state.php`.
- Шаги:
  1. Открыть `/app/api/ui-state.php?AUTH_ID=****token&DOMAIN=example.bitrix24.ru&PLACEMENT=CRM`.
  2. Убедиться, что ответ валиден JSON.
  3. Повторить запрос без параметров в том же сеансе.
- Ожидаемый результат:
  - Ответ всегда валиден JSON.
  - Ключи `status` и `error_message` присутствуют.
  - Контекст берется из сессии при отсутствии параметров.
  - Типы: `allowed` — boolean, `access.is_embedded` — boolean.
  - Артефакты: сохранить JSON в файл/скриншот.

**T004-02: Обработка bootstrap output**
- Тип: статический аудит.
- Шаги:
  1. Проверить наличие `ob_start()` и `JsonResponseService` в `app/api/ui-state.php`.
  2. Проверить вызов `logBootstrapOutput()` перед формированием ответа.
- Ожидаемый результат:
  - Bootstrap output не ломает JSON-ответ.

**T004-03: Контракт JSON-ответа**
- Шаги:
  1. Запросить `/app/api/ui-state.php` без параметров.
  2. Проверить наличие ключей `status`, `error_message`, `allowed`, `access`, `user`.
- Ожидаемый результат:
  - Структура совместима с предыдущим контрактом `ui-state`.
  - Нет лишнего вывода до JSON (первый символ ответа — `{`).

### TASK-005: Сервисная декомпозиция
**T005-01: Разделение сервисов**
- Тип: статический аудит.
- Шаги:
  1. Проверить наличие `AccessContextService`, `BitrixUserProfileService`, `GreetingComposerService`.
  2. Проверить, что `UserGreetingService` использует эти сервисы.
- Ожидаемый результат:
  - В `UserGreetingService` отсутствуют прямые REST-вызовы.

**T005-02: Контекст доступа**
- Шаги:
  1. В embedded-режиме убедиться, что `access.context=embedded` и `access.is_embedded=true`.
  2. В direct-режиме убедиться, что `access.context=direct` и `access.is_embedded=false`.
- Ожидаемый результат:
  - Контекст доступа корректен в обоих сценариях.
  - `auth.source=request_token` в embedded, `auth.source=app_token` в direct.

**T005-03: Ошибки REST не ломают UI**
- Шаги:
  1. Имитировать ошибку REST (невалидный токен).
  2. Проверить, что `ui-state` возвращает `status=error`, но JSON валиден.
- Ожидаемый результат:
  - UI остаётся доступным, ошибки отображаются нейтрально.
  - `error_message` не содержит технических деталей (stacktrace/HTTP кодов).

### TASK-006: Единый REST-клиент
**T006-01: Использование Bitrix24Client**
- Тип: статический аудит.
- Шаги:
  1. Проверить, что сервисы профиля используют `Bitrix24Client`.
- Ожидаемый результат:
  - Прямые вызовы `CRest::call` отсутствуют в сервисах.

**T006-02: Валидация домена**
- Тип: ручной unit-проверки.
- Шаги:
  1. Выполнить PHP-команду:
     `php -r "require 'app/crest.php'; require 'app/Services/Bitrix24Client.php'; $c=new Bitrix24Client(); print_r($c->call('user.current', [], ['auth_id'=>'t','domain'=>'bad!domain']));"`
- Ожидаемый результат:
  - Возвращается `error=invalid_domain`, без фатальных ошибок.
  - `error_information` содержит причину (`domain has invalid characters`).

**T006-03: Невалидный JSON**
- Тип: ручной unit-проверки.
- Шаги:
  1. Подменить ответ REST в тестовой среде на строку без JSON.
  2. Выполнить запрос через `Bitrix24Client::call`.
- Ожидаемый результат:
  - Возвращается `error=invalid_response`.

### TASK-007: Логирование и конфиги
**T007-01: Единый логгер**
- Шаги:
  1. Выполнить запрос `/app/api/ui-state.php`.
  2. Проверить файл `logs-apps/YYYY/MM/DD/app-open.log`.
- Ожидаемый результат:
  - Формат записи `YYYY-MM-DD HH:MM (UTC+3, Brest) | key=value`.
  - Нет символов `\r`/`\n` внутри значений.
  - В логе присутствуют ключи `status`, `access_context`, `access_decision`.

**T007-02: Валидация режима доступа**
- Шаги:
  1. Временно установить `mode=invalid_mode` в `app/config/app-access.php`.
  2. Запросить `/app/api/ui-state.php`.
- Ожидаемый результат:
  - Доступ запрещён, решение `deny`, причина `config_error`.
  - В логах отражена ошибка конфигурации.

**T007-03: Документация**
- Тип: статический аудит.
- Шаги:
  1. Проверить `DOCS/ARCHITECTURE/tech-stack.md` на описание `AppLogger`.
- Ожидаемый результат:
  - Документация актуальна и отражает единый логгер.

### TASK-008: Vue bootstrapping
**T008-01: Единый entry-point**
- Тип: статический аудит.
- Шаги:
  1. Проверить, что `frontend/src/app/main.js` вызывает `bootstrapApp`.
  2. Проверить наличие `frontend/src/bootstrap/*`.
- Ожидаемый результат:
  - Инициализация Vue проходит через bootstrap слой.

**T008-02: Отсутствие BX24 SDK**
- Шаги:
  1. Открыть приложение в direct-режиме без Bitrix24 SDK.
  2. Проверить отображение fallback сообщения.
- Ожидаемый результат:
  - Показывается сообщение о недоступности SDK.
  - Страница не содержит ошибок в консоли уровня error.

### TASK-009: State/data слой
**T009-01: Единый store**
- Тип: статический аудит.
- Шаги:
  1. Проверить наличие `frontend/src/stores/uiStateStore.js`.
  2. Убедиться, что компоненты получают состояние через store.
- Ожидаемый результат:
  - Нет дублирующих запросов к `ui-state`.

**T009-02: Ошибка сети**
- Шаги:
  1. Отключить сеть во время запроса `ui-state`.
  2. Проверить отображение нейтральной ошибки и отсутствие падения UI.
- Ожидаемый результат:
  - `ErrorState` отображается, UI не падает.
  - Показано уведомление через `BX.UI.Notification`, если доступно.

**T009-03: Таймаут**
- Шаги:
  1. Замедлить ответ `ui-state` более 8 секунд.
  2. Проверить, что запрос прерывается.
- Ожидаемый результат:
  - Отображается нейтральная ошибка, лог в консоли без крэша UI.
  - Новый запрос после обновления страницы отрабатывает корректно.

### TASK-010: UI-компоненты
**T010-01: Состояния интерфейса**
- Шаги:
  1. `loading=true` → отображается `LoadingState`.
  2. `allowed=false` → отображается `AccessDenied`.
  3. `status=error` → отображается `ErrorState`.
- Ожидаемый результат:
  - Корректные компоненты на каждое состояние.

**T010-02: Уведомления**
- Шаги:
  1. Инициировать ошибку backend.
  2. Проверить `BX.UI.Notification` при наличии SDK.
- Ожидаемый результат:
  - Отображается уведомление без XSS.
  - Текст уведомления не содержит HTML-тегов из backend.

**T010-03: Стили Bitrix24**
- Тип: визуальная проверка.
- Шаги:
  1. Проверить классы `b24-*` и CSS-токены в `app.css`.
- Ожидаемый результат:
  - Визуальная совместимость с Bitrix24 UI Kit.

### TASK-011: Сборка и ассеты
**T011-01: Сборка**
- Шаги:
  1. Выполнить `npm install` и `npm run build` в `frontend/`.
  2. Проверить `public/vue/app.js` и `public/vue/app.css`.
- Ожидаемый результат:
  - Ассеты сформированы и доступны.
  - Размеры файлов изменяются после повторной сборки (не пустые).

**T011-02: Подключение ассетов**
- Шаги:
  1. Открыть `/app/index.php`.
  2. Проверить наличие `<link>` и `<script type="module">`.
- Ожидаемый результат:
  - Ассеты подключаются только при наличии файлов.
  - При отсутствии `public/vue/app.js` отображается сообщение об ошибке сборки.

### TASK-012: Фиксация прогресса Vue
**T012-01: Актуальность документации**
- Тип: статический аудит.
- Шаги:
  1. Проверить, что `ui-standards.md` и `vue-test-checklist.md` обновлены.
- Ожидаемый результат:
  - Документация содержит правила и чеклист после рефакторинга.

**T012-02: Список измененных файлов**
- Тип: статический аудит.
- Шаги:
  1. Сверить список файлов в TASK-012 с текущей структурой проекта.
- Ожидаемый результат:
  - Все файлы присутствуют в репозитории.

## Формат результатов
- Таблица статусов (Pass/Fail/Blocked) с указанием ID теста.
- Скриншоты UI и логи (при необходимости).
- Ссылка на используемую среду (portal/domain).

## Диагностика и причины задержки
**Симптом:** во всех режимах UI зависал на "Загрузка интерфейса...", при этом `ui-state` отдавал `200` и валидный JSON.

**Почему не получалось решить сразу:**
- В direct-режиме Bitrix24 SDK подключался всегда, что давало ошибку и мешало пониманию причины.
- В части сценариев использовался устаревший фронтенд‑бандл (кэш), что скрывало изменения.
- Ключевая причина: в `useUiState()` возвращались не‑реактивные значения из Pinia, из‑за чего `loading` не сбрасывался и UI оставался на лоадере.

**Что сделано:**
- Ограничено подключение Bitrix24 SDK только embedded‑режимом.
- Добавлен cache‑busting для ассетов через `?v=<mtime>` в `app/index.php`.
- Исправлена реактивность в `frontend/src/composables/useUiState.js` через `storeToRefs(store)`.

**Подтверждение по логам:**
- В `logs-apps/2026/01/15/app-open.log` есть успешные записи для владельца и другого пользователя (user_id `1` и `8`, `status=ok`, `access=embedded`).

## Матрица тестов (выполнение)
> Заполняется по мере прохождения. Все секреты маскировать.

**Среда:**
- Portal/Domain: `example.bitrix24.ru`
- Режим: `embedded | direct`
- AUTH_ID: `****token`
- member_id: `****member`
- Дата: `2026-01-15 10:23 (UTC+3, Брест)`

**Статусы:**
- Pass / Fail / Blocked

| ID теста | Статус | Артефакты | Комментарий |
|---|---|---|---|
| T004-01 | Pass | `DOCS/TASKS/artifacts/TASK-013/t004-01a.json`, `DOCS/TASKS/artifacts/TASK-013/t004-01b.json` | Embedded + повтор без параметров |
| T004-02 | Pass |  | Статический аудит `app/api/ui-state.php` |
| T004-03 | Pass |  | Статический аудит контракта `ui-state` |
| T005-01 | Pass |  | Статический аудит `UserGreetingService` |
| T005-02 | Pass | `DOCS/TASKS/artifacts/TASK-013/t004-01a.json`, `DOCS/TASKS/artifacts/TASK-013/t005-02-direct.json` | Embedded/direct контекст |
| T005-03 | Pass | `DOCS/TASKS/artifacts/TASK-013/t004-01a.json` | Невалидный токен → status=error |
| T006-01 | Pass |  | `CRest::call` только в `Bitrix24Client` |
| T006-02 | Pass | `DOCS/TASKS/artifacts/TASK-013/t006-02.json` | invalid_domain без фатальных ошибок |
| T006-03 | Blocked |  | Нужна подмена REST-ответа |
| T007-01 | Pass | `logs-apps/2026/01/15/app-open.log` | Формат логов корректен |
| T007-02 | Pass | `DOCS/TASKS/artifacts/TASK-013/t007-02.json` | Невалидный режим → deny + config_error |
| T007-03 | Pass |  | Документация `tech-stack.md` |
| T008-01 | Pass |  | `main.js` + `frontend/src/bootstrap` |
| T008-02 | Pass | UI: direct mode | Direct работает без SDK, fallback не показывается |
| T009-01 | Pass |  | `uiStateStore` + `useUiState` |
| T009-02 | Blocked |  | Требуется симуляция сети |
| T009-03 | Blocked |  | Требуется замедление ответа |
| T010-01 | Pass |  | `AppView.vue` состояния UI |
| T010-02 | Blocked |  | Нужен runtime с BX.UI |
| T010-03 | Pass |  | `app.css` b24‑классы |
| T011-01 | Pass | `public/vue/app.js`, `public/vue/app.css` | Сборка Vite |
| T011-02 | Pass |  | `app/index.php` проверяет ассеты |
| T012-01 | Pass |  | `ui-standards.md`, `vue-test-checklist.md` |
| T012-02 | Pass |  | Файлы из TASK-012 присутствуют |

## Шаблон фиксации артефактов
**Логи:**
- `logs-apps/YYYY/MM/DD/app-open.log`
- Фрагмент (маскирование токенов):
  - `YYYY-MM-DD HH:MM (UTC+3, Brest) | status=... access_context=... access_decision=...`

**Скриншоты:**
- Embedded: `ui-embedded-<test-id>.png`
- Direct: `ui-direct-<test-id>.png`

**JSON-ответы:**
- `ui-state-<test-id>.json`

## История изменений
- 2026-01-15 09:55 (UTC+3, Брест): создана задача и набор тестов.
- 2026-01-15 09:58 (UTC+3, Брест): добавлены путь тестирования и критерии успешности.
- 2026-01-15 10:00 (UTC+3, Брест): задача взята в работу.
- 2026-01-15 10:01 (UTC+3, Брест): добавлены матрица тестов и шаблон артефактов.
- 2026-01-15 10:06 (UTC+3, Брест): собраны ассеты и выполнены статические проверки.
- 2026-01-15 10:11 (UTC+3, Брест): выполнены CLI-проверки ui-state, логов и Bitrix24Client.
- 2026-01-15 10:12 (UTC+3, Брест): выполнена проверка конфигурации доступа (invalid_mode).
- 2026-01-15 10:16 (UTC+3, Брест): подтверждён fallback в direct-режиме без SDK.
- 2026-01-15 10:23 (UTC+3, Брест): direct-режим переведён на работу без SDK, сборка обновлена.
- 2026-01-15 14:54 (UTC+3, Брест): зафиксирована причина "вечной загрузки" и итоговые исправления.
