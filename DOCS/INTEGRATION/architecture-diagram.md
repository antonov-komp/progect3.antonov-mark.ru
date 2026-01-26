# Схема архитектуры интеграции с Bitrix24

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

---

## Общая схема работы

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          Bitrix24 Портал                                 │
│                                                                           │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │  iframe: https://your-app.com/app/index.php                       │  │
│  │  Параметры: AUTH_ID, DOMAIN, IFRAME, B24_FRAME                   │  │
│  │                                                                   │  │
│  │  ┌─────────────────────────────────────────────────────────────┐ │  │
│  │  │         REST-приложение (ваше приложение)                   │ │  │
│  │  │                                                              │ │  │
│  │  │  ┌──────────────────────────────────────────────────────┐  │ │  │
│  │  │  │  Backend (PHP)                                       │  │ │  │
│  │  │  │                                                       │  │ │  │
│  │  │  │  • RequestContextService                             │  │ │  │
│  │  │  │    - Чтение параметров из $_REQUEST                  │  │ │  │
│  │  │  │    - Сохранение в $_SESSION                          │  │ │  │
│  │  │  │                                                       │  │ │  │
│  │  │  │  • AccessContextService                              │  │ │  │
│  │  │  │    - Определение контекста (embedded/direct)        │  │ │  │
│  │  │  │    - Получение auth context (AUTH_ID, DOMAIN)      │  │ │  │
│  │  │  │                                                       │  │ │  │
│  │  │  │  • Bitrix24Client                                    │  │ │  │
│  │  │  │    - Вызов Bitrix24 REST API                        │  │ │  │
│  │  │  │    - Использование AUTH_ID и DOMAIN                │  │ │  │
│  │  │  │                                                       │  │ │  │
│  │  │  │  • REST API Endpoints                               │  │ │  │
│  │  │  │    - /api/ui-state.php                              │  │ │  │
│  │  │  │    - /api/access-config.php                        │  │ │  │
│  │  │  │    - /api/access-directory.php                     │  │ │  │
│  │  │  │    - /api/access-modules.php                       │  │ │  │
│  │  │  └──────────────────────────────────────────────────────┘  │ │  │
│  │  │                                                              │ │  │
│  │  │  ┌──────────────────────────────────────────────────────┐  │ │  │
│  │  │  │  Frontend (Vue.js)                                    │  │ │  │
│  │  │  │                                                       │  │ │  │
│  │  │  │  • appBootstrap.js                                    │  │ │  │
│  │  │  │    - Разрешение контекста                            │  │ │  │
│  │  │  │    - Инициализация BX24 SDK                          │  │ │  │
│  │  │  │    - Монтирование Vue.js приложения                  │  │ │  │
│  │  │  │                                                       │  │ │  │
│  │  │  │  • bitrixSdk.js                                      │  │ │  │
│  │  │  │    - Инициализация window.BX24                      │  │ │  │
│  │  │  │    - Проверка доступности SDK                       │  │ │  │
│  │  │  │                                                       │  │ │  │
│  │  │  │  • Vue.js компоненты                                 │  │ │  │
│  │  │  │    - Использование BX24 для UI-взаимодействий       │  │ │  │
│  │  │  │    - Запросы к backend API                          │  │ │  │
│  │  │  │    - Управление состоянием (Pinia)                  │  │ │  │
│  │  │  └──────────────────────────────────────────────────────┘  │ │  │
│  │  └─────────────────────────────────────────────────────────────┘ │  │
│  └───────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ REST API запросы
                                    │ (с AUTH_ID и DOMAIN)
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                    Bitrix24 REST API                                     │
│                                                                           │
│  • crm.lead.list                                                         │
│  • crm.lead.get                                                          │
│  • user.current                                                           │
│  • user.get                                                               │
│  • department.get                                                         │
│  • и другие методы...                                                    │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Поток данных при загрузке приложения

```
1. Bitrix24 загружает iframe
   │
   ├─► URL: https://your-app.com/app/index.php?
   │   AUTH_ID=abc123xyz&
   │   DOMAIN=your-portal.bitrix24.ru&
   │   IFRAME=1&
   │   B24_FRAME=1
   │
   ▼
2. Backend (app/index.php)
   │
   ├─► session_start()
   │
   ├─► RequestContextService::getContext()
   │   ├─► Чтение параметров из $_REQUEST
   │   ├─► Сохранение в $_SESSION
   │   └─► Возврат контекста
   │
   ├─► Определение isEmbedded
   │   └─► Проверка PLACEMENT, IFRAME, B24_FRAME
   │
   ├─► Вывод HTML:
   │   ├─► <div id="app">
   │   ├─► <script>window.APP_REQUEST_CONTEXT = {...}</script>
   │   ├─► <script src="//api.bitrix24.com/api/v1/"></script> (если embedded)
   │   └─► <script type="module" src="/vue/app.js"></script>
   │
   ▼
3. Frontend (appBootstrap.js)
   │
   ├─► resolveRequestContext()
   │   └─► Объединение window.APP_REQUEST_CONTEXT
   │
   ├─► Проверка isEmbeddedContext()
   │
   ├─► initBitrixSdk() (если embedded)
   │   ├─► waitForDomReady()
   │   ├─► Проверка window.BX24
   │   └─► window.BX24.init()
   │
   └─► mountVueApp()
       ├─► createApp(AppView)
       ├─► app.use(createPinia())
       ├─► app.use(router)
       └─► app.mount('#app')
   │
   ▼
4. Vue.js компоненты
   │
   ├─► Запросы к backend API
   │   └─► fetch('/api/ui-state.php?AUTH_ID=...&DOMAIN=...')
   │
   ├─► Использование BX24 SDK
   │   ├─► window.BX24.getAuth()
   │   ├─► window.BX24.callMethod()
   │   └─► window.BX24.showNotification()
   │
   └─► Отображение интерфейса
```

---

## Поток данных при API-запросе

```
1. Vue.js компонент делает запрос
   │
   └─► fetch('/api/ui-state.php?AUTH_ID=abc123&DOMAIN=portal.bitrix24.ru')
   │
   ▼
2. Backend (app/api/ui-state.php)
   │
   ├─► AccessContextService::getAuthContext()
   │   ├─► Чтение AUTH_ID из $_REQUEST или $_SESSION
   │   ├─► Чтение DOMAIN из $_REQUEST или $_SESSION
   │   └─► Возврат ['auth_id' => '...', 'domain' => '...']
   │
   ├─► Bitrix24Client::call('user.current', [], $authContext)
   │   ├─► Проверка hasTokenContext()
   │   ├─► callWithToken()
   │   │   ├─► Формирование URL: https://{domain}/rest/user.current.json
   │   │   ├─► Добавление auth в params
   │   │   └─► Выполнение curl-запроса
   │   └─► Возврат результата
   │
   └─► Формирование JSON-ответа
       └─► { "status": "ok", "data": {...} }
   │
   ▼
3. Frontend получает ответ
   │
   └─► Обработка данных в Vue.js компоненте
       └─► Отображение в интерфейсе
```

---

## Компоненты и их взаимодействие

### Backend компоненты

```
RequestContextService
    │
    ├─► Читает $_REQUEST
    ├─► Сохраняет в $_SESSION
    └─► Возвращает контекст
         │
         ▼
AccessContextService
    │
    ├─► Определяет isEmbeddedRequest()
    ├─► getAccessContext() → ['context' => 'embedded', 'is_embedded' => true]
    └─► getAuthContext() → ['auth_id' => '...', 'domain' => '...']
         │
         ▼
Bitrix24Client
    │
    ├─► call($method, $params, $authContext)
    ├─► callWithToken() → REST API через curl
    └─► callWithCrest() → REST API через CRest (коробка)
```

### Frontend компоненты

```
appBootstrap.js
    │
    ├─► resolveRequestContext()
    ├─► initBitrixSdk()
    └─► mountVueApp()
         │
         ├─► bitrixSdk.js
         │   ├─► waitForDomReady()
         │   └─► window.BX24.init()
         │
         └─► Vue.js приложение
             ├─► Компоненты
             ├─► Services (apiClient.js, requestContext.js)
             └─► Stores (Pinia)
```

---

## Контекст авторизации

### Источники AUTH_ID

```
1. $_REQUEST['AUTH_ID']          (из URL параметров)
2. $_REQUEST['access_token']      (альтернативное имя)
3. $_REQUEST['auth']              (строка или массив)
4. $_SESSION['AUTH_ID']           (из предыдущего запроса)
```

### Источники DOMAIN

```
1. $_REQUEST['DOMAIN']            (из URL параметров)
2. $_REQUEST['domain']             (альтернативное имя)
3. $_SESSION['DOMAIN']             (из предыдущего запроса)
```

### Приоритет

```
1. Параметры из текущего запроса ($_REQUEST)
2. Параметры из сессии ($_SESSION)
3. Пустые значения, если ничего не найдено
```

---

## Определение режима встраивания

### Признаки embedded-контекста

```
isEmbeddedRequest() возвращает true, если:
    ├─► !empty($_REQUEST['PLACEMENT'])
    ├─► !empty($_REQUEST['PLACEMENT_OPTIONS'])
    ├─► !empty($_REQUEST['IFRAME'])
    ├─► !empty($_REQUEST['B24_FRAME'])
    ├─► !empty($_SESSION['PLACEMENT'])
    ├─► !empty($_SESSION['PLACEMENT_OPTIONS'])
    ├─► !empty($_SESSION['IFRAME'])
    └─► !empty($_SESSION['B24_FRAME'])
```

### Режимы работы

```
embedded  → Приложение встроено в Bitrix24 через iframe
direct     → Приложение открыто напрямую (не в iframe)
unknown    → Контекст не определён (нет параметров и сессии)
```

---

## Связанные документы

- [Интеграция с Bitrix24 через iframe](./bitrix24-iframe-integration.md) — подробное описание интеграции
- [README](./README.md) — навигация по разделу интеграции
