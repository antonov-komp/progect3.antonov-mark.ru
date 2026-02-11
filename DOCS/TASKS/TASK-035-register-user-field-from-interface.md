# TASK-035: Регистрация нового пользовательского поля из интерфейса модуля «Пользовательские поля»

**Дата создания:** 2026-02-11 (UTC+3, Брест)  
**Статус:** Черновик  
**Приоритет:** Средний  
**Исполнитель:** Bitrix24 Программист (Vue.js)

## Описание

Добавить возможность **регистрации нового кастомного (пользовательского) поля** Bitrix24 CRM непосредственно из интерфейса модуля «Пользовательские поля». Пользователь, находясь на странице нужного раздела (Сделки, Лиды, Контакты, Компании или Смарт-процесс), создаёт своё поле через форму. После успешной регистрации поле появляется в таблице раздела и становится доступным в формах карточек CRM.

## Что именно регистрируем

| Тип поля | Описание | Наша задача |
|----------|----------|-------------|
| **Стандартные поля B24** | Встроенные поля Bitrix24: Название, Компания, Контакт, Сумма, Статус и т.д. | Не трогаем |
| **Кастомные (пользовательские) поля** | Дополнительные поля, которые создаёт бизнес под свои процессы. Код вида `UF_CRM_*`, настраиваемые типы (строка, число, список, дата и т.д.) | Регистрируем из интерфейса |

Мы создаём **своё кастомное поле** в нужном разделе CRM — не стандартное, а то, которое нужно бизнесу. Например: «Источник заявки», «Менеджер по сделке», «Дата следующего звонка» и т.п.

## Контекст

- **TASK-034** реализовала модуль «Пользовательские поля» с разделами и таблицей полей (только просмотр).
- Текущий интерфейс: навигация по разделам → таблица полей выбранного раздела.
- Регистрация кастомных полей в Bitrix24 ранее выполнялась вручную в настройках CRM или через скрипты (см. progect2: `register-userfield.php`).
- Цель: единая точка входа — пользователь создаёт **своё поле** в контексте раздела, не выходя из приложения.

**Важно:** Регистрируемое поле — это **пользовательское поле (userfield)** в форме редактирования элемента CRM, а **не** placement (отдельная вкладка). Поле появляется внутри стандартной формы карточки сделки/лида/контакта и т.д.

---

## Сценарий использования (User Flow)

### 1. Точка входа

- Пользователь открывает модуль «Пользовательские поля» → выбирает раздел (например, «Сделки»).
- На странице списка полей раздела отображается таблица полей и кнопка **«Создать поле»**.

### 2. Открытие формы создания

- Клик по «Создать поле» → открывается модальное окно (или форма на странице) с полями:
  - **Название поля** (LABEL / EDIT_FORM_LABEL) — обязательно
  - **Код поля** (FIELD_NAME) — по желанию генерируется автоматически (UF_CRM_DEAL_XXXX)
  - **Тип поля** (USER_TYPE_ID) — выпадающий список: string, integer, double, boolean, date, datetime, money, url, enumeration, crm_status, crm и др.
  - **Обязательное** (MANDATORY) — Y/N
  - **Множественное** (MULTIPLE) — Y/N
  - **Показывать в списке** (SHOW_IN_LIST) — Y/N
  - **Показывать в фильтре** (SHOW_FILTER) — Y/N
  - **Сортировка** (SORT) — число, по умолчанию 100

### 3. Отправка и ответ

- Пользователь заполняет форму и нажимает «Создать».
- Backend вызывает Bitrix24 REST API (`crm.deal.userfield.add` и аналоги).
- При успехе: закрыть форму, обновить список полей, показать уведомление «Поле создано».
- При ошибке: показать текст ошибки (например, «Код поля уже существует», «Недостаточно прав»).

### 4. Смарт-процессы

- Для раздела «Смарт-процесс» дополнительно передаётся `entityTypeId` / `typeId` текущего выбранного смарт-процесса.
- Используется метод `userfieldconfig.add` (или `crm.userfield.add` с ENTITY_ID = CRM_{typeId}).

---

## Модули и компоненты

### Backend (PHP)

- `app/Services/UserFieldService.php`
  - `addDealUserField(array $fields): int|false` — создание поля сделки
  - `addLeadUserField(array $fields): int|false`
  - `addContactUserField(array $fields): int|false`
  - `addCompanyUserField(array $fields): int|false`
  - `addSmartProcessUserField(string $entityId, array $fields): int|false` — для смарт-процессов

- `app/api/user-fields.php`
  - Текущий GET — без изменений
  - Добавить обработку **POST**:
    - Параметры: `section`, `entityTypeId` (для smart), `typeId` (для smart)
    - Тело запроса (JSON): `fields` — объект с атрибутами поля
    - Ответ: `{ status: "ok", field_id: 6997 }` или `{ status: "error", error_message: "..." }`

### Frontend (Vue 3)

- `frontend/src/components/user-fields/UserFieldsTable.vue`
  - Добавить кнопку «Создать поле» в шапку секции (рядом с «Назад к разделам»)
  - При клике — эмит `@create` или открытие модалки

- `frontend/src/components/user-fields/UserFieldsCreateFieldModal.vue` (новый)
  - Модальное окно с формой
  - Поля: название, код (опционально), тип, обязательное, множество, в списке, в фильтре, сортировка
  - Валидация на клиенте
  - Вызов `createUserField()` при submit

- `frontend/src/services/userFieldsService.js`
  - `createUserField(section, fields, entityTypeId?, typeId?): Promise<{ field_id }>`

- `frontend/src/stores/userFieldsStore.js`
  - Action `createField(section, fields, entityTypeId?, typeId?)` — вызов API, при успехе — перезагрузка `loadFields`

### API Bitrix24

| Сущность         | Метод                    | Документация |
|------------------|--------------------------|--------------|
| Сделки           | `crm.deal.userfield.add` | https://apidocs.bitrix24.com/api-reference/crm/deals/user-defined-fields/crm-deal-userfield-add.html |
| Лиды             | `crm.lead.userfield.add` | https://apidocs.bitrix24.ru/rest/crm/leads/userfield/crm_lead_userfield_add.html |
| Контакты         | `crm.contact.userfield.add` | https://apidocs.bitrix24.ru/rest/crm/contacts/userfield/crm_contact_userfield_add.html |
| Компании         | `crm.company.userfield.add` | https://apidocs.bitrix24.ru/rest/crm/companies/userfield/crm_company_userfield_add.html |
| Смарт-процессы   | `userfieldconfig.add` или `crm.userfield.add` | https://apidocs.bitrix24.com/api-reference/crm/universal/userfieldconfig/ |

---

## Параметры поля (fields)

Минимальный набор для `crm.deal.userfield.add` (аналогично для lead/contact/company):

| Параметр         | Обязательность | Описание |
|------------------|----------------|----------|
| `USER_TYPE_ID`   | Да             | string, integer, double, boolean, date, datetime, money, url, enumeration, crm_status, crm и др. |
| `FIELD_NAME`     | Да             | Код поля. Для deal: без префикса UF_CRM_, Bitrix может добавлять автоматически. Макс. 20 символов, допустимы A-Z, 0-9, _ |
| `EDIT_FORM_LABEL` или `LABEL` | Да | Человекочитаемое название |
| `MANDATORY`      | Нет (по умолч. N) | Y / N |
| `MULTIPLE`       | Нет (по умолч. N) | Y / N |
| `SHOW_IN_LIST`   | Нет (по умолч. N) | Y / N |
| `SHOW_FILTER`    | Нет (по умолч. N) | Y / N |
| `SORT`           | Нет (по умолч. 100) | Целое число > 0 |
| `EDIT_IN_LIST`   | Нет            | Y / N |
| `IS_SEARCHABLE`  | Нет            | Y / N |
| `SETTINGS`       | Нет            | Объект с доп. настройками по типу (ROWS для string, PRECISION для double, ENTITY_TYPE для crm_status и т.д.) |
| `LIST`           | Для enumeration | Массив `[{ VALUE, SORT, DEF, XML_ID }]` |

**Автогенерация FIELD_NAME:** если пользователь не указал код, сгенерировать `UF_CRM_{SECTION}_` + timestamp или случайная строка, чтобы обеспечить уникальность (например, `UF_CRM_DEAL_1739192345`).

---

## Зависимости

- TASK-034 (модуль «Пользовательские поля»)
- Bitrix24 REST API, scope `crm`
- Права: для `crm.*.userfield.add` — обычно CRM-администратор
- AccessControlService — доступ к модулю уже контролируется

---

## Ступенчатые подзадачи

### Этап 1: Backend — добавление полей

1. **UserFieldService — методы добавления**
   - `addDealUserField(array $fields): int|false` — вызов `crm.deal.userfield.add`
   - `addLeadUserField`, `addContactUserField`, `addCompanyUserField` — аналогично
   - `addSmartProcessUserField(string $entityId, array $fields): int|false` — вызов `userfieldconfig.add` (moduleId: crm, field.entityId: CRM_{entityId}) или запасной `crm.userfield.add`
   - Нормализация полей (FIELD_NAME с префиксом по сущности при необходимости)
   - Обработка ошибок API, логирование через AppLogger

2. **API user-fields.php — POST**
   - Проверка `REQUEST_METHOD === 'POST'`
   - Парсинг JSON body: `section`, `entityTypeId`, `typeId`, `fields`
   - Валидация section ∈ { deal, lead, contact, company, smart }
   - Для smart: обязателен entityTypeId или typeId
   - Вызов соответствующего метода UserFieldService
   - Ответ: `{ status, field_id }` или `{ status, error_message }`

### Этап 2: Frontend — форма и API

3. **userFieldsService.js — createUserField**
   - `createUserField(section, fields, entityTypeId?, typeId?, signal?): Promise`
   - POST `/api/user-fields.php` с JSON body
   - Передача auth-контекста через query (или заголовки) по аналогии с GET

4. **UserFieldsCreateFieldModal.vue**
   - Поля формы: название (обязательно), код поля (опционально, placeholder «Авто»), тип (select), обязательное, множество, в списке, в фильтре, сортировка
   - Список типов: string, integer, double, boolean, date, datetime, money, url, enumeration, crm_status, crm
   - Валидация: название не пустое, код — только A-Z, 0-9, _ если указан
   - Кнопки: «Создать», «Отмена»
   - При успехе: emit `@created`, закрыть модалку
   - При ошибке: показать error_message под формой

5. **UserFieldsTable.vue — интеграция**
   - Добавить кнопку «Создать поле» в `user-fields-table-section__header`
   - При клике: открыть UserFieldsCreateFieldModal
   - Передать в модалку: section, entityTypeId, typeId (из роута/пропсов)
   - При `@created`: вызвать `loadFields()` в store, показать BX.UI.Notification

6. **userFieldsStore.js**
   - Добавить `createField(section, fields, entityTypeId?, typeId?, signal?)`
   - После успешного создания — вызвать `loadFields()` для обновления списка

### Этап 3: Тестирование и доработка

7. **Тестирование**
   - Создание поля типа string в разделе Сделки
   - Создание поля в разделе Контакты, Компании, Лиды
   - Создание поля в разделе Смарт-процесс (на конкретный тип)
   - Проверка ошибок: дублирование кода, неверный тип, недостаток прав
   - Проверка отображения нового поля в таблице после создания

8. **Опционально: расширенная форма**
   - Секция «Дополнительные настройки» для SETTINGS (по типу поля)
   - Для enumeration — возможность указать элементы списка
   - Для crm_status — выбор ENTITY_TYPE

---

## API-спецификация POST /api/user-fields.php

**Request:**
```
POST /api/user-fields.php
Content-Type: application/json

Query (auth): member_id, domain, access_token, ... (как для GET)

Body:
{
  "section": "deal",
  "entityTypeId": null,
  "typeId": null,
  "fields": {
    "FIELD_NAME": "MY_CUSTOM_FIELD",
    "USER_TYPE_ID": "string",
    "EDIT_FORM_LABEL": "Моё поле",
    "MANDATORY": "N",
    "MULTIPLE": "N",
    "SHOW_IN_LIST": "Y",
    "SHOW_FILTER": "Y",
    "SORT": 100
  }
}
```

Для `section: "smart"` обязательно указать `entityTypeId` или `typeId`.

**Response (success):**
```json
{
  "status": "ok",
  "field_id": 6997
}
```

**Response (error):**
```json
{
  "status": "error",
  "error_message": "Field name contains invalid characters..."
}
```

---

## Критерии приёмки

- [ ] На странице списка полей раздела отображается кнопка «Создать поле»
- [ ] По клику открывается модальное окно с формой создания
- [ ] Форма содержит: название, код поля (опц.), тип, обязательное, множество, в списке, в фильтре, сортировка
- [ ] При отправке формы выполняется POST к API, вызывается соответствующий метод Bitrix24
- [ ] При успехе: модалка закрывается, список полей обновляется, показывается уведомление
- [ ] При ошибке: отображается сообщение об ошибке
- [ ] Поддержаны все разделы: Сделки, Лиды, Контакты, Компании, Смарт-процессы
- [ ] Новое поле появляется в Bitrix24 в стандартной форме карточки элемента (не placement)
- [ ] Поле является кастомным (UF_CRM_*), а не стандартным встроенным полем B24

## Тестирование

1. Открыть модуль → раздел «Сделки» → «Создать поле»
2. Заполнить: название «Тест», тип «Строка», код «TEST_FIELD»
3. Создать → проверить появление поля в таблице
4. Открыть карточку любой сделки в Bitrix24 → проверить наличие поля «Тест» в форме
5. Повторить для Контактов, Компаний, Лидов
6. Для смарт-процесса: выбрать раздел смарт-процесса → создать поле → проверить в карточке элемента смарт-процесса

## История правок

- 2026-02-11 (UTC+3, Брест): Черновик задачи создан на основе TASK-034 и анализа progect2 (register-userfield.php, crm.deal.userfield.add).
- 2026-02-11 (UTC+3, Брест): Уточнён контекст: явно разделены стандартные поля B24 и кастомные (пользовательские) поля; добавлена таблица «Что именно регистрируем».
