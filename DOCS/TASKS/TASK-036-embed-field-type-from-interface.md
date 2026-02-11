# TASK-036: Регистрация поля-встройки (кастомный тип с Handler) из интерфейса

**Дата создания:** 2026-02-11 (UTC+3, Брест)  
**Статус:** Детализировано  
**Приоритет:** Средний  
**Исполнитель:** Bitrix24 Программист (Vue.js)

## Описание

Добавить возможность **регистрации поля-встройки** — пользовательского поля Bitrix24 CRM с **кастомным типом** (userfieldtype). В карточке CRM такое поле отображается как iframe с URL-handler’а. Контент handler’а (HTML/JS) загружает данные через REST API и показывает интерактивный интерфейс «внутри поля» (кнопки, выбор подписанта, запуск БП и т.п.).

Референс: [progect2.antonov-mark.ru](https://github.com/antonov-komp/progect2.antonov-mark.ru) — `register-userfield.php`, `contact-buttons.php`, `company-buttons.php`.

## Что именно регистрируем

| Тип поля | Описание | Наша задача |
|----------|----------|-------------|
| **Стандартные типы B24** | string, integer, date, enumeration и т.д. | TASK-035 |
| **Поля-встройки** | Кастомный `USER_TYPE_ID` (например, `contact_buttons_view`), зарегистрированный через `userfieldtype.add` с `HANDLER` = URL iframe. Bitrix24 показывает handler как iframe внутри поля в форме карточки | Регистрируем тип + поле из интерфейса |

## Контекст

- **TASK-035** реализовала создание «обычных» полей (строка, число, дата и т.д.) — текущая форма `UserFieldsCreateFieldModal.vue` содержит select с типами string, integer и т.д.
- **TASK-036** расширяет форму: добавляется переключатель режима. При выборе «Поле-встройка» форма меняется на поля для регистрации userfieldtype + поля CRM.
- В progect2 поля-встройки создаются скриптами:
  1. `userfieldtype.add` — регистрация кастомного типа (USER_TYPE_ID, HANDLER, TITLE, DESCRIPTION)
  2. `crm.deal.userfield.add` — создание поля с этим типом
- Цель: единая точка входа — пользователь создаёт **поле-встройку** через модуль «Пользовательские поля», указывая URL handler’а и параметры.

---

## Сценарий использования (User Flow)

### 1. Точка входа

- Пользователь открывает модуль «Пользовательские поля» → выбирает раздел (например, «Сделки»).
- В форме создания поля появляется переключатель: **«Обычное поле»** (по умолчанию) / **«Поле-встройка (iframe)»**.

### 2. Режим «Обычное поле»

- Совпадает с TASK-035: название, код поля, тип (string, integer…), чекбоксы. Без изменений.

### 3. Режим «Поле-встройка»

При выборе «Поле-встройка» форма дополняется:

- **URL handler’а** — обязательно (страница, которую Bitrix24 отобразит в iframe)
- **Код типа поля** (USER_TYPE_ID) — обязательно, уникальный (например, `my_buttons_view`). Валидация: `[a-z0-9_]`, макс. 50 символов
- **Название поля** (EDIT_FORM_LABEL)
- **Описание типа** (для userfieldtype)
- **Код поля** (FIELD_NAME) — опционально, авто-генерация
- **Раздел** — Сделки / Лиды / Контакты / Компании (смарт-процессы — по возможности)

### 4. Отправка и ответ

- Backend:
  1. Вызов `userfieldtype.add` (или `userfieldtype.update`, если тип уже есть)
  2. Вызов `crm.deal.userfield.add` (или lead/contact/company) с `USER_TYPE_ID` = код типа
- При успехе: закрыть форму, обновить список полей, уведомление «Поле-встройка создано».

---

## Модули и компоненты

### Backend (PHP)

- `app/Services/UserFieldTypeService.php` (новый)
  - `registerUserFieldType(string $userTypeId, string $handlerUrl, string $title, string $description): bool` — вызов `userfieldtype.add`; при ошибке «тип существует» — `userfieldtype.update`
  - `getLastError(): string` — для передачи сообщения об ошибке в API-ответ

- `app/Services/UserFieldService.php`
  - Добавить метод `addEmbedField(string $section, array $params): int|false`
  - params: `handler_url`, `user_type_id`, `label`, `field_name`? (опц.), `description`? (опц.)
  - Внутри: вызов UserFieldTypeService::registerUserFieldType, затем addDealUserField и т.д. с USER_TYPE_ID = user_type_id

- `app/api/user-fields.php`
  - Добавить обработку POST с `mode: "embed"`:
    - `handler_url`, `user_type_id`, `field_name`, `label`, `description`, `section`

### API Bitrix24 (детализация)

**Документация:** https://apidocs.bitrix24.com/api-reference/widgets/user-field/userfieldtype-add.html

| Метод | Назначение |
|-------|------------|
| `userfieldtype.add` | Регистрация кастомного типа поля |
| `userfieldtype.update` | Обновление типа (если уже существует) |
| `crm.deal.userfield.add` | Создание поля с кастомным типом |

**Параметры userfieldtype.add:**

| Параметр | Тип | Обязательность | Описание |
|----------|-----|----------------|----------|
| `USER_TYPE_ID` | string | Да | Уникальный код типа. Допустимы: a-z, 0-9, _ |
| `HANDLER` | string | Да | Полный URL страницы для iframe |
| `TITLE` | string | Да | Название типа (для администраторов) |
| `DESCRIPTION` | string | Нет | Описание типа |
| `BASE_TYPE` | string | Нет | Базовый тип (по умолчанию `string`) |

**Возможные ошибки:** тип уже существует → вызывать `userfieldtype.update`; scope виджетов — приложение должно иметь права на userfieldtype.

### Frontend (Vue 3)

- `UserFieldsCreateFieldModal.vue`
  - `fieldMode: ref<'standard' | 'embed'>` — переключатель в начале формы
  - При `embed`: форма с полями handler_url, user_type_id, label, description, field_name (опц.)
  - Условный рендер: v-if fieldMode === 'standard' — текущая форма; v-else — форма embed
  - При submit в режиме embed: вызов `createEmbedField(section, params)`
- `userFieldsService.js` — `createEmbedField(section, params, signal)` — POST с mode: "embed"

---

## Зависимости

- TASK-034 (модуль «Пользовательские поля»)
- TASK-035 (создание обычных полей)
- Bitrix24 REST API: scope для `userfieldtype` (если требуется отдельный)
- Референс: progect2 `register-userfield.php`, `register-contact-buttons-field.sh`

---

## Ступенчатые подзадачи (по этапам)

### Этап 1: Backend — UserFieldTypeService

1. Создать `app/Services/UserFieldTypeService.php`
2. Реализовать `registerUserFieldType(userTypeId, handlerUrl, title, description): bool`
3. При ошибке «type exists» — fallback на `userfieldtype.update`
4. Интеграция с Bitrix24Client, AccessContextService, AppLogger

### Этап 2: Backend — UserFieldService и API

5. Добавить в `UserFieldService` метод `addEmbedField(section, params): int|false`
6. В `addEmbedField`: вызов UserFieldTypeService, затем addDealUserField/addLeadUserField и т.д.
7. В `app/api/user-fields.php`: обработка `mode === "embed"`, валидация, вызов addEmbedField

### Этап 3: Frontend — форма

8. В `UserFieldsCreateFieldModal.vue`: переключатель режима, условный рендер формы
9. Добавить поля для embed: handler_url, user_type_id, label, description, field_name
10. Валидация на клиенте (URL, код типа a-z0-9_, название)
11. Вызов `createEmbedField` при submit в режиме embed

### Этап 4: Frontend — сервис и store

12. В `userFieldsService.js`: функция `createEmbedField(section, params, signal)`
13. В `userFieldsStore.js`: при необходимости — отдельный action или расширение createField

### Этап 5: Тестирование

14. Создать поле-встройку в разделе Сделки
15. Открыть карточку сделки в Bitrix24 — проверить iframe с handler URL
16. Проверить ошибки: неверный URL, дублирование user_type_id

---

## API-спецификация POST для mode=embed

**Request:**
```http
POST /api/user-fields.php
Content-Type: application/json

{
  "section": "deal",
  "mode": "embed",
  "handler_url": "https://example.com/handler.php",
  "user_type_id": "my_buttons_view",
  "label": "Мои кнопки",
  "field_name": "",
  "description": "Показывает кнопки действий"
}
```

**Response (success):** `{ "status": "ok", "field_id": 7010 }`

**Response (error):** `{ "status": "error", "error_message": "..." }`

**Валидация на backend:** handler_url — filter_var FILTER_VALIDATE_URL; user_type_id — preg_match `/^[a-z0-9_]{1,50}$/`; label — не пусто; section ∈ { deal, lead, contact, company } (смарт-процессы — в backlog).

---

## Параметры handler'а (PLACEMENT_OPTIONS)

Bitrix24 передаёт в iframe URL handler'а с query-параметрами: `ENTITY_VALUE_ID` (ID сущности), `ENTITY_TYPE_ID` (1=лид, 2=сделка, 3=контакт, 4=компания), `ENTITY_ID` (CRM_DEAL и т.д.). Handler должен учитывать их для загрузки данных через REST API.

---

## Ограничения

1. **Смарт-процессы:** В первой итерации — только deal, lead, contact, company. Смарт-процессы требуют проверки поддержки userfieldconfig.add для кастомных типов.
2. **Scope Bitrix24:** Приложение должно иметь права на userfieldtype (виджеты).
3. **Handler URL:** Рекомендуется HTTPS (mixed content может блокироваться).

---

## Критерии приёмки

- [ ] В форме создания поля есть переключатель «Обычное поле» / «Поле-встройка»
- [ ] При режиме «Поле-встройка» отображаются поля: URL handler, код типа, название, описание (опц.), код поля (опц.)
- [ ] Валидация: URL формат, код типа a-z0-9_, название не пустое
- [ ] При создании вызываются `userfieldtype.add` (или update) и `crm.*.userfield.add`
- [ ] Поддерживаются разделы: Сделки, Лиды, Контакты, Компании
- [ ] В карточке сделки/лида/контакта/компании поле отображается как iframe с указанным URL
- [ ] Handler получает контекст (ENTITY_VALUE_ID и др.)
- [ ] При ошибке API отображается понятное сообщение
- [ ] Режим «Обычное поле» по-прежнему работает (регрессия TASK-035)

---

## Референс (progect2)

```bash
# register-contact-buttons-field.sh
php register-userfield.php \
  --handler-url="${HANDLER_URL}?sourceField=${SOURCE_FIELD}" \
  --user-type-id="contact_buttons_view" \
  --title="Контакты кнопками" \
  --description="Показывает контакты из поля-источника кнопками" \
  --field-name="UF_CRM_CONTACT_BUTTONS" \
  --xml-id="UF_CRM_CONTACT_BUTTONS"
```

---

## Тестирование (чек-лист)

1. Открыть модуль → Сделки → «Создать поле»
2. Выбрать «Поле-встройка»
3. Заполнить: URL `https://example.com/test.html`, код типа `test_embed_view`, название «Тест встройки»
4. Создать → проверить появление поля в таблице
5. Открыть карточку любой сделки в Bitrix24 → убедиться, что поле отображается как iframe
6. Повторить для Лидов, Контактов, Компаний
7. Проверить ошибку: неверный URL, дублирование кода типа
8. Проверить режим «Обычное поле» (регрессия TASK-035)

---

## История правок

- 2026-02-11 (UTC+3, Брест): Черновик на основе progect2 и плана полей-встроек.
- 2026-02-11 (UTC+3, Брест): Детализация (Технический писатель): параметры API Bitrix24, API-спецификация POST, ступенчатые подзадачи по этапам, ограничения, PLACEMENT_OPTIONS, критерии приёмки, чек-лист тестирования.
