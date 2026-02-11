# TASK-036: Регистрация поля-встройки (кастомный тип с Handler) из интерфейса

**Дата создания:** 2026-02-11 (UTC+3, Брест)  
**Статус:** Черновик  
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

- **TASK-035** реализовала создание «обычных» полей (строка, число, дата и т.д.).
- В progect2 поля-встройки создаются скриптами:
  1. `userfieldtype.add` — регистрация кастомного типа (USER_TYPE_ID, HANDLER, TITLE, DESCRIPTION)
  2. `crm.deal.userfield.add` — создание поля с этим типом
- Цель: единая точка входа — пользователь создаёт **поле-встройку** через модуль «Пользовательские поля», указывая URL handler’а и параметры.

---

## Сценарий использования (User Flow)

### 1. Точка входа

- Пользователь открывает модуль «Пользовательские поля» → выбирает раздел (например, «Сделки»).
- В форме создания поля появляется опция: **«Обычное поле»** / **«Поле-встройка (iframe)»**.

### 2. Режим «Поле-встройка»

При выборе «Поле-встройка» форма дополняется:

- **URL handler’а** — обязательно (страница, которую Bitrix24 отобразит в iframe)
- **Код типа поля** (USER_TYPE_ID) — обязательно, уникальный (например, `my_buttons_view`)
- **Название поля** (EDIT_FORM_LABEL)
- **Описание типа** (для userfieldtype)
- **Код поля** (FIELD_NAME) — опционально, авто-генерация
- **Раздел** — Сделки / Лиды / Контакты / Компании (смарт-процессы — по возможности)

### 3. Отправка и ответ

- Backend:
  1. Вызов `userfieldtype.add` (или `userfieldtype.update`, если тип уже есть)
  2. Вызов `crm.deal.userfield.add` (или lead/contact/company) с `USER_TYPE_ID` = код типа
- При успехе: закрыть форму, обновить список полей, уведомление «Поле-встройка создано».

---

## Модули и компоненты

### Backend (PHP)

- `app/Services/UserFieldTypeService.php` (новый)
  - `registerUserFieldType(string $userTypeId, string $handlerUrl, string $title, string $description): bool`
  - Вызов `userfieldtype.add` / `userfieldtype.update`

- `app/Services/UserFieldService.php`
  - Расширить: поддержка `USER_TYPE_ID` = кастомный тип при создании поля
  - Или: `addEmbedField(section, handlerUrl, userTypeId, fieldName, label, description): int|false`

- `app/api/user-fields.php`
  - Добавить обработку POST с `mode: "embed"`:
    - `handler_url`, `user_type_id`, `field_name`, `label`, `description`, `section`

### API Bitrix24

| Метод | Назначение |
|-------|------------|
| `userfieldtype.add` | Регистрация кастомного типа поля |
| `userfieldtype.update` | Обновление типа (если уже существует) |
| `crm.deal.userfield.add` | Создание поля с кастомным типом |

**Параметры userfieldtype.add:**

- `USER_TYPE_ID` — уникальный код типа (например, `contact_buttons_view`)
- `HANDLER` — URL страницы для iframe
- `TITLE` — название типа
- `DESCRIPTION` — описание
- `BASE_TYPE` — обычно `string`

### Frontend (Vue 3)

- `UserFieldsCreateFieldModal.vue`
  - Переключатель: «Обычное поле» / «Поле-встройка»
  - При «Поле-встройка» — дополнительные поля: URL handler, код типа, описание

---

## Зависимости

- TASK-034 (модуль «Пользовательские поля»)
- TASK-035 (создание обычных полей)
- Bitrix24 REST API: scope для `userfieldtype` (если требуется отдельный)
- Референс: progect2 `register-userfield.php`, `register-contact-buttons-field.sh`

---

## Ступенчатые подзадачи

1. **UserFieldTypeService** — методы `registerUserFieldType`, проверка/обновление типа
2. **UserFieldService** — создание поля с кастомным USER_TYPE_ID после регистрации типа
3. **API user-fields.php** — ветка POST для `mode: "embed"`
4. **Форма** — переключатель режима + поля для встройки
5. **Тестирование** — создание поля-встройки, проверка отображения iframe в карточке сделки

---

## Критерии приёмки

- [ ] В форме создания поля есть режим «Поле-встройка»
- [ ] Пользователь указывает URL handler’а и код типа
- [ ] При создании вызываются `userfieldtype.add` (или update) и `crm.*.userfield.add`
- [ ] В карточке сделки/лида/контакта/компании поле отображается как iframe с указанным URL
- [ ] Handler получает `PLACEMENT_OPTIONS` (ENTITY_VALUE_ID и др.) для загрузки данных

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

## История правок

- 2026-02-11 (UTC+3, Брест): Черновик на основе progect2 и плана полей-встроек.
