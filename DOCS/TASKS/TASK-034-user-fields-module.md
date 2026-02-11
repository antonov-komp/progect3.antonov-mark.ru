# TASK-034: Модуль «Пользовательские поля»

**Дата создания:** 2026-02-11 (UTC+3, Брест)  
**Статус:** Черновик  
**Приоритет:** Средний  
**Исполнитель:** Bitrix24 Программист (Vue.js)

## Описание

Создать новый модуль приложения для работы с пользовательскими полями Bitrix24 CRM. Модуль объединяет кастомные поля следующих сущностей: Сделки, Лиды, Контакты, Компании и все смарт-процессы проекта. Интерфейс имеет двухуровневую навигацию: сначала выбор раздела, затем просмотр всех полей выбранной сущности.

## Контекст

В Bitrix24 CRM пользовательские поля (UF) настраиваются отдельно для каждой сущности. Для REST-приложения нужен единый обзор всех UF по всему CRM — для аналитики, интеграций и настройки. Модуль расширяет функциональность существующего приложения (Access Modules, Greeting) и использует текущую архитектуру (backend PHP → JSON API → Vue frontend).

---

## Сценарий использования (User Flow)

### 1. Вход в модуль

- **Точка входа:** плитка «Пользовательские поля» на главной странице приложения (в блоке «Модули» рядом с Отчёты, Заявки, Документы и т.д.)
- **Маршрут:** `/user-fields` (или `/modules/user-fields` по аналогии с другими модулями)
- **Доступ:** тот же, что для остальных модулей — через AccessControlService и конфиг модулей (DEFAULT_MODULES / access-modules.php)

**Регистрация модуля в конфиге:**
```javascript
{
  key: 'module_user_fields',
  title: 'Пользовательские поля',
  subtitle: 'Сделки, лиды, контакты, компании, смарт-процессы',
  icon: 'field',
  route: '/user-fields',
  enabled: true,
  allowed_users: [],
  allowed_departments: [],
}
```

### 2. Страница навигации разделов

После входа пользователь попадает на **страницу выбора раздела**. На ней отображаются разделы:

| Раздел | Описание | Источник API |
|--------|----------|--------------|
| **Сделки** | Пользовательские поля сделок | `crm.deal.userfield.list` |
| **Лиды** | Пользовательские поля лидов | `crm.lead.userfield.list` |
| **Контакты** | Пользовательские поля контактов | `crm.contact.userfield.list` |
| **Компании** | Пользовательские поля компаний | `crm.company.userfield.list` |
| **Смарт-процессы** | Список типов смарт-процессов | `crm.type.list` |

**Важно:** Смарт-процессы загружаются **сразу при открытии модуля** (при монтировании страницы навигации). Вызов `crm.type.list` выполняется один раз. Результат — список типов (id, title, entityTypeId) отображается в виде плиток или вложенных пунктов меню.

**Вариант отображения:**
- Плитки (как AccessModuleTiles): 4 фиксированные плитки + динамический список плиток смарт-процессов
- Или список/меню: Сделки | Лиды | Контакты | Компании | Смарт-процесс: Заявки | Смарт-процесс: Договоры | …

### 3. Получение полей по разделу

При клике на раздел (или на конкретный смарт-процесс):
- Переход на страницу `/user-fields/:section` или `/user-fields/:entityType`
- Где `section` = `deal` | `lead` | `contact` | `company` | `smart`
- Для смарт-процессов: `section` = `smart`, дополнительный параметр `entityTypeId` = `134` (пример)

**Запрос к API:**
- `GET /api/user-fields.php?section=deal` — поля сделок
- `GET /api/user-fields.php?section=lead` — поля лидов
- `GET /api/user-fields.php?section=contact` — поля контактов
- `GET /api/user-fields.php?section=company` — поля компаний
- `GET /api/user-fields.php?section=smart&entityTypeId=134` — поля смарт-процесса с ID 134

**Ответ:** массив полей в нормализованном виде (см. структуру поля ниже).

### 4. Отображение полей

На странице раздела выводится **таблица полей**. Каждое поле описывается следующими атрибутами:

| Атрибут | Название в UI | Ключ API | Описание |
|---------|---------------|----------|----------|
| **Название пользовательское** | Название поля | `EDIT_FORM_LABEL` или `LIST_COLUMN_LABEL` | Человекочитаемое название (для формы / списка) |
| **ID технический** | Код поля | `FIELD_NAME` | Уникальный код поля (напр. `UF_CRM_1713790573`) |
| **Тип поля** | Тип | `USER_TYPE_ID` | string, integer, double, boolean, datetime, date, money, url, address, enumeration, file, employee, crm_status, crm и др. |
| **ID записи** | ID | `ID` | Числовой идентификатор поля в Bitrix24 |
| **Сущность** | Сущность | `ENTITY_ID` | CRM_DEAL, CRM_LEAD, CRM_CONTACT, CRM_COMPANY, DYNAMIC_134 и т.д. |
| **Обязательное** | Обязательное | `MANDATORY` | Y / N |
| **Множественное** | Множественное | `MULTIPLE` | Y / N |
| **Сортировка** | Сортировка | `SORT` | Число для порядка вывода |
| **В списке** | Показывать в списке | `SHOW_IN_LIST` | Y / N |
| **Редактирование в списке** | Редактировать в списке | `EDIT_IN_LIST` | Y / N |
| **В фильтре** | Показывать в фильтре | `SHOW_FILTER` | N / I / E / S |
| **Поиск** | Участвует в поиске | `IS_SEARCHABLE` | Y / N |
| **Внешний код** | XML_ID | `XML_ID` | Внешний идентификатор (если задан) |
| **Настройки** | Настройки | `SETTINGS` | Объект с доп. параметрами (размер, список значений и т.д.) |
| **Подсказка** | Подсказка | `HELP_MESSAGE` | Текст подсказки (если есть) |
| **Сообщение об ошибке** | Сообщение об ошибке | `ERROR_MESSAGE` | Текст при валидации (если задан) |

**Для полей типа `enumeration`** дополнительно возвращается `LIST` — массив вариантов (ID, SORT, VALUE, DEF, XML_ID).

---

## Структура пользовательского поля (полная)

Структура одного поля в ответе Bitrix24 (userfield.list):

```json
{
  "ID": "5815",
  "ENTITY_ID": "CRM_DEAL",
  "FIELD_NAME": "UF_CRM_1713790573",
  "USER_TYPE_ID": "crm_status",
  "XML_ID": null,
  "SORT": "100",
  "MULTIPLE": "Y",
  "MANDATORY": "N",
  "SHOW_FILTER": "I",
  "SHOW_IN_LIST": "Y",
  "EDIT_IN_LIST": "Y",
  "IS_SEARCHABLE": "N",
  "SETTINGS": { "ENTITY_TYPE": "INDUSTRY" },
  "EDIT_FORM_LABEL": "Directory",
  "LIST_COLUMN_LABEL": "Directory",
  "LIST_FILTER_LABEL": "Directory",
  "ERROR_MESSAGE": null,
  "HELP_MESSAGE": null
}
```

**Типы полей (USER_TYPE_ID):** string, integer, double, boolean, datetime, date, money, url, address, enumeration, file, employee, crm_status, iblock_section, iblock_element, crm, resourcebooking и [другие](https://apidocs.bitrix24.ru/api-reference/crm/universal/user-defined-fields/userfield-type.html).

**Локализованные метки:** `EDIT_FORM_LABEL`, `LIST_COLUMN_LABEL`, `LIST_FILTER_LABEL` могут быть объектом `{"ru": "Текст", "en": "Text"}` при передаче `filter.LANG`, либо строкой. В UI использовать приоритет: `EDIT_FORM_LABEL` → `LIST_COLUMN_LABEL` → `FIELD_NAME`.

---

## Модули и компоненты

### Backend (PHP)

- `app/Services/UserFieldService.php` — сервис работы с UF через Bitrix24 REST API
  - `getDealUserFields()` — поля сделок
  - `getLeadUserFields()` — поля лидов
  - `getContactUserFields()` — поля контактов
  - `getCompanyUserFields()` — поля компаний
  - `getSmartProcessTypes()` — список типов смарт-процессов (`crm.type.list`)
  - `getSmartProcessUserFields(string $entityTypeId)` — поля смарт-процесса (`crm.item.userfield.list`)
  - Нормализация: добавление `entity_source` (deal|lead|contact|company|smart), извлечение `TITLE` из меток

- `app/api/user-fields.php` — JSON-эндпоинт
  - **Секции (разделы):** `GET ?section=sections` — возвращает список разделов + смарт-процессы (результат `crm.type.list`)
  - **Поля раздела:** `GET ?section=deal` | `?section=lead` | `?section=contact` | `?section=company`
  - **Поля смарт-процесса:** `GET ?section=smart&entityTypeId=134`

- `public/api/user-fields.php` — прокси

### Frontend (Vue 3)

- `frontend/src/views/UserFieldsView.vue` — корневая страница модуля (роут `/user-fields`)
  - Условный рендер: если `section` не выбран — показывать `UserFieldsSections.vue`, иначе — `UserFieldsList.vue`

- `frontend/src/views/UserFieldsSectionsView.vue` — страница навигации разделов
  - При монтировании: загрузка `sections` (включая смарт-процессы через `crm.type.list`)
  - Компонент `UserFieldsSectionTiles.vue` — плитки разделов

- `frontend/src/views/UserFieldsListView.vue` — страница списка полей раздела
  - Параметры: `section` (deal|lead|contact|company|smart), `entityTypeId` (для smart)
  - Загрузка полей при монтировании
  - Компонент `UserFieldsTable.vue` — таблица полей

- `frontend/src/components/user-fields/`
  - `UserFieldsSectionTiles.vue` — плитки разделов (Сделки, Лиды, Контакты, Компании + плитки смарт-процессов)
  - `UserFieldsTable.vue` — таблица полей с колонками (Название, ID технический, Тип, Обязательное, Множественное, Сортировка, В списке, В фильтре и т.д.)
  - `UserFieldsFieldDetail.vue` — раскрытие строки с полными атрибутами и SETTINGS (опционально)
  - `UserFieldsBreadcrumb.vue` — хлебные крошки (Пользовательские поля → Сделки)

- `frontend/src/services/userFieldsService.js` — клиент API
  - `fetchSections()` — разделы + смарт-процессы
  - `fetchFieldsBySection(section, entityTypeId?)` — поля раздела

- `frontend/src/stores/userFieldsStore.js` — Pinia
  - `sections`, `smartTypes`, `fields`, `currentSection`, `currentEntityTypeId`, `loading`, `error`

### Конфигурация и доступ

- Добавить модуль в `DEFAULT_MODULES` (uiStateStore.js) и/или в конфиг access-modules
- Роутер: `/user-fields`, `/user-fields/:section`, `/user-fields/smart/:entityTypeId`
- Контроль доступа: AccessControlService

## Зависимости

- Bitrix24 REST API (CRest / Bitrix24Client)
- Система контроля доступа (AccessControlService)
- Роутер и плитки модулей (AccessModuleTiles, uiStateStore DEFAULT_MODULES)
- Методы Bitrix24: `crm.deal.userfield.list`, `crm.lead.userfield.list`, `crm.contact.userfield.list`, `crm.company.userfield.list`, `crm.type.list`, `crm.item.userfield.list`

---

## Ступенчатые подзадачи

### Этап 1: Backend — сервис и API

1. **UserFieldService**
   - Методы: `getDealUserFields()`, `getLeadUserFields()`, `getContactUserFields()`, `getCompanyUserFields()`
   - Метод `getSmartProcessTypes()` — вызов `crm.type.list`
   - Метод `getSmartProcessUserFields(string $entityTypeId)` — вызов `crm.item.userfield.list`
   - Нормализация: `entity_source`, извлечение `TITLE` из `EDIT_FORM_LABEL` / `LIST_COLUMN_LABEL` / `FIELD_NAME`
   - Обработка ошибок, логирование через AppLogger

2. **API user-fields.php**
   - Параметр `section`: `sections` | `deal` | `lead` | `contact` | `company` | `smart`
   - Параметр `entityTypeId` (обязателен при `section=smart`)
   - При `section=sections`: вызвать `getSmartProcessTypes()`, вернуть `{ sections: [...], smart_types: [...] }`
   - При остальных section: вызвать соответствующий метод, вернуть `{ user_fields: [...] }`
   - Прокси `public/api/user-fields.php`

### Этап 2: Frontend — навигация и загрузка

3. **Роутер и страницы**
   - Маршруты: `/user-fields`, `/user-fields/:section`, `/user-fields/smart/:entityTypeId`
   - `UserFieldsView.vue` — корень, роутинг на Sections или List

4. **Страница разделов**
   - `UserFieldsSectionsView.vue` — при монтировании вызывать `fetchSections()` (смарт-процессы подгружаются сразу)
   - `UserFieldsSectionTiles.vue` — плитки: Сделки, Лиды, Контакты, Компании + плитки по каждому смарт-процессу
   - Клик по плитке → переход на `/user-fields/deal`, `/user-fields/smart/134` и т.д.

5. **Страница полей**
   - `UserFieldsListView.vue` — чтение `section`, `entityTypeId` из роута
   - При монтировании: `fetchFieldsBySection(section, entityTypeId)`
   - Рендер `UserFieldsTable.vue` + `UserFieldsBreadcrumb.vue`

6. **Сервис и store**
   - `userFieldsService.js`: `fetchSections()`, `fetchFieldsBySection(section, entityTypeId?)`
   - `userFieldsStore.js`: sections, smartTypes, fields, currentSection, loading, error

### Этап 3: Таблица полей

7. **UserFieldsTable.vue**
   - Колонки: Название пользовательское, ID технический (FIELD_NAME), Тип поля, ID записи, Обязательное, Множественное, Сортировка, В списке, Редактирование в списке, В фильтре, Поиск
   - Опционально: раскрываемая строка с полным набором атрибутов (SETTINGS, HELP_MESSAGE, ERROR_MESSAGE)

8. **Регистрация модуля**
   - Добавить в DEFAULT_MODULES (uiStateStore.js): `module_user_fields` с route `/user-fields`
   - Добавить иконку `field` в `resolveIconLabel` (AccessModuleTiles)

9. **Опционально: экспорт**
   - Кнопка «Экспорт» — выгрузка таблицы в JSON или CSV

---

## API-методы Bitrix24

| Метод | Сущность | Документация |
|-------|----------|--------------|
| `crm.deal.userfield.list` | Сделки | https://apidocs.bitrix24.ru/api-reference/crm/deals/user-defined-fields/crm-deal-userfield-list.html |
| `crm.lead.userfield.list` | Лиды | https://apidocs.bitrix24.ru/api-reference/crm/leads/userfield/crm-lead-userfield-list.html |
| `crm.contact.userfield.list` | Контакты | https://apidocs.bitrix24.ru/api-reference/crm/contacts/userfield/crm-contact-userfield-list.html |
| `crm.company.userfield.list` | Компании | https://apidocs.bitrix24.ru/api-reference/crm/companies/userfield/crm-company-userfield-list.html |
| `crm.type.list` | Типы смарт-процессов | https://apidocs.bitrix24.ru/api-reference/crm/universal/user-defined-object-types/index |
| `crm.item.userfield.list` | Поля смарт-процесса | https://apidocs.bitrix24.ru/api-reference/crm/universal/user-defined-fields/ |

**Параметры `crm.item.userfield.list`:**
```php
['entityTypeId' => '134']  // ID типа из crm.type.list
```

**Параметр `filter.LANG`** (опционально): при передаче `ru` или `en` в ответе возвращаются локализованные метки (EDIT_FORM_LABEL, LIST_COLUMN_LABEL и т.д.) для выбранного языка.

## Технические требования

- **Backend:** PHP 8.4+, использование Bitrix24Client / CRest
- **Frontend:** Vue 3 (Composition API), Pinia, Vite
- **Стили:** соответствие BX + Vue (см. DOCS/GUIDES/ui-standards.md)
- **Логирование:** AppLogger для ошибок API
- **Обработка ошибок:** частичный сбой по одной сущности не должен ломать весь ответ; в `meta.errors` — детали

## Структура ответов API user-fields.php

### Ответ при section=sections

```json
{
  "status": "ok",
  "sections": [
    { "id": "deal", "title": "Сделки", "entityId": "CRM_DEAL" },
    { "id": "lead", "title": "Лиды", "entityId": "CRM_LEAD" },
    { "id": "contact", "title": "Контакты", "entityId": "CRM_CONTACT" },
    { "id": "company", "title": "Компании", "entityId": "CRM_COMPANY" }
  ],
  "smart_types": [
    { "id": "134", "entityTypeId": "134", "title": "Заявки" },
    { "id": "136", "entityTypeId": "136", "title": "Договоры" }
  ]
}
```

### Ответ при section=deal|lead|contact|company|smart

```json
{
  "status": "ok",
  "section": "deal",
  "entityTypeId": null,
  "user_fields": [
    {
      "ID": "5815",
      "ENTITY_ID": "CRM_DEAL",
      "FIELD_NAME": "UF_CRM_1713790573",
      "USER_TYPE_ID": "crm_status",
      "TITLE": "Directory",
      "XML_ID": null,
      "SORT": "100",
      "MULTIPLE": "Y",
      "MANDATORY": "N",
      "SHOW_FILTER": "I",
      "SHOW_IN_LIST": "Y",
      "EDIT_IN_LIST": "Y",
      "IS_SEARCHABLE": "N",
      "SETTINGS": { "ENTITY_TYPE": "INDUSTRY" },
      "EDIT_FORM_LABEL": "Directory",
      "LIST_COLUMN_LABEL": "Directory",
      "LIST_FILTER_LABEL": "Directory",
      "ERROR_MESSAGE": null,
      "HELP_MESSAGE": null,
      "entity_source": "deal"
    }
  ],
  "total": 1
}
```

Для `section=smart` в ответ добавляется `entityTypeId`.

---

## Критерии приёмки

- [ ] Вход в модуль: плитка «Пользовательские поля» на главной странице ведёт на `/user-fields`
- [ ] Страница навигации разделов отображает: Сделки, Лиды, Контакты, Компании, смарт-процессы (подгружаются сразу при открытии)
- [ ] При клике на раздел загружаются все поля раздела и отображаются в таблице
- [ ] Таблица полей содержит колонки: Название пользовательское, ID технический, Тип поля, ID записи, Обязательное, Множественное, Сортировка, В списке, Редактирование в списке, В фильтре, Поиск
- [ ] UserFieldService покрывает все 5 категорий (Deal, Lead, Contact, Company, Smart)
- [ ] API возвращает корректный JSON для `section=sections` и `section=deal|lead|contact|company|smart`
- [ ] Ошибки API логируются, UI показывает fallback при сбое
- [ ] Доступ контролируется через AccessControlService

## Тестирование

1. **Вход:** открыть приложение → клик по плитке «Пользовательские поля» → переход на `/user-fields`
2. **Разделы:** при открытии модуля сразу загружаются смарт-процессы, отображаются плитки всех разделов
3. **Поля:** клик по «Сделки» → загрузка полей → таблица с атрибутами (Название, ID технический, Тип и т.д.)
4. **Смарт-процесс:** клик по плитке смарт-процесса → загрузка полей этого типа → таблица
5. **Ошибки:** проверить fallback при недоступности Bitrix24 API
6. **Доступ:** embedded (в Bitrix24), direct (с ограничениями)

## История правок

- 2026-02-11 (UTC+3, Брест): Черновик задачи создан.
- 2026-02-11 (UTC+3, Брест): Детализация: сценарий входа, страница навигации разделов, подгрузка смарт-процессов при открытии, структура поля (название, тип, ID технический и др.), ступенчатые подзадачи, структура API-ответов.
