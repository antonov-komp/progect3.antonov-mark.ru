# Интеграция приложения с Bitrix24 через iframe

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0  
**Автор:** Технический писатель / Аналитик

---

## Оглавление

1. [Обзор архитектуры](#обзор-архитектуры)
2. [Как работает встраивание в Bitrix24](#как-работает-встраивание-в-bitrix24)
3. [Определение контекста встраивания](#определение-контекста-встраивания)
4. [Инициализация приложения](#инициализация-приложения)
5. [Работа с Bitrix24 SDK](#работа-с-bitrix24-sdk)
6. [Авторизация и контекст запросов](#авторизация-и-контекст-запросов)
7. [Поток данных](#поток-данных)
8. [Особенности работы в iframe](#особенности-работы-в-iframe)

---

## Обзор архитектуры

### Концепция

Приложение представляет собой **REST-приложение**, которое работает **внутри портала Bitrix24 через iframe**. Это означает:

- Приложение загружается как внешний ресурс в iframe внутри интерфейса Bitrix24
- Приложение получает контекст авторизации от Bitrix24 через параметры запроса
- Приложение использует Bitrix24 REST API для работы с данными портала
- Приложение использует Bitrix24 SDK (BX24) для взаимодействия с интерфейсом портала

### Технологический стек

**Backend:**
- PHP 8.4+ (REST API endpoints)
- CRest библиотека для работы с Bitrix24 REST API
- Сессии PHP для сохранения контекста

**Frontend:**
- Vue.js 3.x (Composition API)
- Pinia для управления состоянием
- Bitrix24 SDK (BX24) для интеграции с порталом

**Интеграция:**
- Bitrix24 REST API для работы с данными
- Bitrix24 SDK для UI-взаимодействий
- iframe для встраивания в интерфейс портала

---

## Как работает встраивание в Bitrix24

### Механизм встраивания

Приложение встраивается в Bitrix24 через **прямую ссылку**, которая открывается внутри портала в iframe. Процесс выглядит следующим образом:

```
┌─────────────────────────────────────────────────────────────┐
│                    Bitrix24 Портал                          │
│  ┌───────────────────────────────────────────────────────┐  │
│  │  iframe (src="https://your-app.com/app/index.php")    │  │
│  │  ┌─────────────────────────────────────────────────┐  │  │
│  │  │  Ваше REST-приложение                           │  │  │
│  │  │  - Vue.js интерфейс                             │  │  │
│  │  │  - PHP REST API                                 │  │  │
│  │  │  - Bitrix24 SDK (BX24)                          │  │  │
│  │  └─────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

### Параметры запроса от Bitrix24

Когда Bitrix24 загружает приложение в iframe, он передаёт следующие параметры через URL:

- `AUTH_ID` — токен авторизации для REST API
- `DOMAIN` — домен портала Bitrix24 (например, `your-portal.bitrix24.ru`)
- `member_id` — идентификатор портала
- `PLACEMENT` — тип размещения (если используется placement)
- `PLACEMENT_OPTIONS` — опции размещения (JSON)
- `IFRAME` — флаг встраивания в iframe (`1` или `true`)
- `B24_FRAME` — альтернативный флаг встраивания

### Пример URL при встраивании

```
https://your-app.com/app/index.php?
  AUTH_ID=abc123xyz&
  DOMAIN=your-portal.bitrix24.ru&
  member_id=b24_abcd1234&
  IFRAME=1&
  B24_FRAME=1
```

---

## Определение контекста встраивания

### Сервис RequestContextService

Приложение использует `RequestContextService` для определения контекста запроса и сохранения его в сессии.

**Файл:** `app/Services/RequestContextService.php`

**Ключевые параметры контекста:**
```php
private const CONTEXT_KEYS = [
    'AUTH_ID',           // Токен авторизации
    'DOMAIN',            // Домен портала
    'member_id',         // ID портала
    'PLACEMENT',         // Тип размещения
    'PLACEMENT_OPTIONS', // Опции размещения
    'IFRAME',            // Флаг iframe
    'B24_FRAME',         // Альтернативный флаг
];
```

**Логика работы:**
1. Сервис читает параметры из `$_REQUEST`
2. Сохраняет их в `$_SESSION` для последующих запросов
3. Возвращает объединённый контекст (из запроса и сессии)

### Сервис AccessContextService

`AccessContextService` определяет, является ли запрос встроенным (embedded) или прямым (direct).

**Файл:** `app/Services/AccessContextService.php`

**Методы определения контекста:**

```php
// Проверка, является ли запрос встроенным
private function isEmbeddedRequest(): bool
{
    // Проверка параметров PLACEMENT, PLACEMENT_OPTIONS, IFRAME, B24_FRAME
    // в $_REQUEST и $_SESSION
}

// Получение контекста доступа
public function getAccessContext(): array
{
    return [
        'context' => 'embedded' | 'direct' | 'unknown',
        'is_embedded' => true | false,
    ];
}

// Получение контекста авторизации
public function getAuthContext(): array
{
    return [
        'domain' => 'your-portal.bitrix24.ru',
        'auth_id' => 'abc123xyz',
    ];
}
```

---

## Инициализация приложения

### Точка входа (Backend)

**Файл:** `app/index.php` или `public/index.php`

**Процесс инициализации:**

1. **Старт сессии:**
   ```php
   session_start();
   ```

2. **Получение контекста запроса:**
   ```php
   $contextService = new RequestContextService($_REQUEST, $_SESSION);
   $requestContext = $contextService->getContext();
   ```

3. **Определение режима встраивания:**
   ```php
   $isEmbedded = !empty($requestContext['PLACEMENT'])
       || !empty($requestContext['PLACEMENT_OPTIONS'])
       || !empty($requestContext['IFRAME'])
       || !empty($requestContext['B24_FRAME']);
   ```

4. **Вывод HTML-разметки:**
   ```php
   echo '<div id="app" class="app-root">';
   echo '<div class="app-loading">Загрузка интерфейса...</div>';
   echo '</div>';
   ```

5. **Передача контекста в JavaScript:**
   ```php
   echo '<script>window.APP_REQUEST_CONTEXT = ' .
       json_encode($requestContext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) .
       ';</script>';
   ```

6. **Подключение Bitrix24 SDK (если встроено):**
   ```php
   if ($isEmbedded) {
       echo '<script src="//api.bitrix24.com/api/v1/"></script>';
   }
   ```

7. **Подключение Vue.js приложения:**
   ```php
   echo '<script type="module" src="/vue/app.js"></script>';
   ```

### Точка входа (Frontend)

**Файл:** `frontend/src/bootstrap/appBootstrap.js`

**Процесс инициализации:**

1. **Разрешение контекста запроса:**
   ```javascript
   await resolveRequestContext();
   const context = window.APP_REQUEST_CONTEXT || {};
   ```

2. **Проверка режима встраивания:**
   ```javascript
   const isEmbedded = Boolean(
       context.PLACEMENT ||
       context.PLACEMENT_OPTIONS ||
       context.IFRAME ||
       context.B24_FRAME
   );
   ```

3. **Инициализация Bitrix24 SDK (если встроено):**
   ```javascript
   if (isEmbedded) {
       const sdkState = await initBitrixSdk();
       if (!sdkState.available) {
           renderFallback(root, sdkState.message);
           return;
       }
   }
   ```

4. **Монтирование Vue.js приложения:**
   ```javascript
   const app = createApp(AppView);
   app.use(createPinia());
   app.use(router);
   app.mount(root);
   ```

---

## Работа с Bitrix24 SDK

### Инициализация SDK

**Файл:** `frontend/src/bootstrap/bitrixSdk.js`

**Процесс инициализации:**

```javascript
export const initBitrixSdk = async () => {
  // Ожидание готовности DOM
  await waitForDomReady();

  // Проверка наличия BX24
  if (!window.BX24 || typeof window.BX24.init !== 'function') {
    return {
      available: false,
      message: 'Bitrix24 SDK недоступен. Откройте приложение внутри портала Bitrix24.',
    };
  }

  // Инициализация SDK
  return new Promise((resolve) => {
    try {
      window.BX24.init(() => {
        resolve({ available: true, message: '' });
      });
    } catch (error) {
      console.warn('BX24 init failed', error);
      resolve({
        available: false,
        message: 'Bitrix24 SDK недоступен.',
      });
    }
  });
};
```

### Использование SDK в приложении

После инициализации SDK доступны следующие возможности:

**1. Получение авторизации:**
```javascript
window.BX24.getAuth((auth) => {
  const authId = auth.auth;
  const domain = auth.domain;
  // Использование для REST API запросов
});
```

**2. Вызов REST API методов:**
```javascript
window.BX24.callMethod('crm.lead.list', {
  filter: { '>CREATED_DATE': '2026-01-01' },
  select: ['ID', 'NAME', 'EMAIL'],
}, (result) => {
  if (result.error()) {
    console.error('Ошибка:', result.error());
  } else {
    const leads = result.data();
    // Обработка данных
  }
});
```

**3. Работа с UI:**
```javascript
// Показ уведомления
window.BX24.showNotification('Сообщение', 'info');

// Открытие попапа
window.BX24.openApplication({
  name: 'app-name',
  width: 800,
  height: 600,
});
```

---

## Авторизация и контекст запросов

### Получение контекста авторизации

**Backend:** `app/Services/AccessContextService.php`

```php
public function getAuthContext(): array
{
    // Получение AUTH_ID из различных источников:
    // 1. $_REQUEST['AUTH_ID']
    // 2. $_REQUEST['access_token']
    // 3. $_REQUEST['auth'] (строка или массив)
    // 4. $_SESSION['AUTH_ID']
    
    // Получение DOMAIN из:
    // 1. $_REQUEST['DOMAIN']
    // 2. $_REQUEST['domain']
    // 3. $_SESSION['DOMAIN']
    
    return [
        'domain' => 'your-portal.bitrix24.ru',
        'auth_id' => 'abc123xyz',
    ];
}
```

**Frontend:** `frontend/src/services/requestContext.js`

```javascript
export const getRequestContext = async () => {
  // Получение контекста из window.APP_REQUEST_CONTEXT
  const context = window.APP_REQUEST_CONTEXT || {};
  
  // Если встроено, получение авторизации через BX24
  if (isEmbeddedContext(context)) {
    return new Promise((resolve) => {
      window.BX24.getAuth((auth) => {
        resolve({
          ...context,
          AUTH_ID: auth.auth,
          DOMAIN: auth.domain,
        });
      });
    });
  }
  
  return context;
};
```

### Использование контекста для REST API

**Backend:** `app/Services/Bitrix24Client.php`

```php
public function call(string $method, array $params = [], array $authContext = []): array
{
    // Если передан контекст авторизации, используем токен
    if ($this->hasTokenContext($authContext)) {
        return $this->callWithToken($method, $params, $authContext);
    }
    
    // Иначе используем CRest (для коробочной версии)
    return $this->callWithCrest($method, $params);
}

private function callWithToken(string $method, array $params, array $authContext): array
{
    $domain = $authContext['domain'];
    $authId = $authContext['auth_id'];
    
    $url = 'https://' . $domain . '/rest/' . $method . '.json';
    $params['auth'] = $authId;
    
    // Выполнение curl-запроса
    // ...
}
```

---

## Поток данных

### Схема взаимодействия

```
┌─────────────────────────────────────────────────────────────┐
│                    Bitrix24 Портал                          │
│                                                               │
│  1. Загрузка iframe с параметрами:                          │
│     AUTH_ID, DOMAIN, IFRAME, B24_FRAME                      │
│                                                               │
│  2. Передача контекста в window.APP_REQUEST_CONTEXT        │
│                                                               │
│  3. Инициализация BX24 SDK                                   │
│                                                               │
└─────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│              REST-приложение (ваше приложение)              │
│                                                               │
│  Backend (PHP):                                              │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ RequestContextService                               │    │
│  │ - Чтение параметров из $_REQUEST                   │    │
│  │ - Сохранение в $_SESSION                            │    │
│  └─────────────────────────────────────────────────────┘    │
│                          │                                    │
│                          ▼                                    │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ AccessContextService                                │    │
│  │ - Определение контекста (embedded/direct)          │    │
│  │ - Получение auth context (AUTH_ID, DOMAIN)        │    │
│  └─────────────────────────────────────────────────────┘    │
│                          │                                    │
│                          ▼                                    │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Bitrix24Client                                      │    │
│  │ - Вызов Bitrix24 REST API                           │    │
│  │ - Использование AUTH_ID и DOMAIN                    │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                               │
│  Frontend (Vue.js):                                          │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ appBootstrap.js                                     │    │
│  │ - Разрешение контекста                              │    │
│  │ - Инициализация BX24 SDK                           │    │
│  │ - Монтирование Vue.js приложения                    │    │
│  └─────────────────────────────────────────────────────┘    │
│                          │                                    │
│                          ▼                                    │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Vue.js компоненты                                   │    │
│  │ - Использование BX24 для UI-взаимодействий           │    │
│  │ - Запросы к backend API                             │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│              Bitrix24 REST API                              │
│                                                               │
│  - crm.lead.list                                            │
│  - user.current                                              │
│  - department.get                                            │
│  - и другие методы...                                        │
└─────────────────────────────────────────────────────────────┘
```

### Пример запроса данных

**1. Frontend запрашивает данные:**
```javascript
// Vue.js компонент
const response = await fetch('/api/ui-state.php?AUTH_ID=...&DOMAIN=...');
const data = await response.json();
```

**2. Backend обрабатывает запрос:**
```php
// app/api/ui-state.php
$accessContextService = new AccessContextService($_REQUEST, $_SESSION);
$authContext = $accessContextService->getAuthContext();

$bitrix24Client = new Bitrix24Client();
$result = $bitrix24Client->call('user.current', [], $authContext);
```

**3. Backend возвращает данные:**
```json
{
  "status": "ok",
  "data": {
    "user": {
      "id": "123",
      "name": "Иван Иванов",
      "email": "ivan@example.com"
    }
  }
}
```

---

## Особенности работы в iframe

### Ограничения iframe

1. **Безопасность (Same-Origin Policy):**
   - Приложение должно быть на том же домене или иметь CORS-настройки
   - Bitrix24 передаёт токены через URL-параметры для обхода ограничений

2. **Размеры окна:**
   - Приложение может запрашивать изменение размера через BX24 SDK:
     ```javascript
     window.BX24.resizeWindow(800, 600);
     ```

3. **Коммуникация с родительским окном:**
   - Используется BX24 SDK для безопасной коммуникации
   - Не рекомендуется использовать `window.parent` напрямую

### Обработка ошибок

**1. Отсутствие SDK:**
```javascript
if (!window.BX24) {
  renderFallback(root, 'Bitrix24 SDK недоступен. Откройте приложение внутри портала Bitrix24.');
  return;
}
```

**2. Ошибки авторизации:**
```php
// Backend проверяет наличие AUTH_ID и DOMAIN
if (empty($authContext['auth_id']) || empty($authContext['domain'])) {
    return [
        'status' => 'error',
        'error_message' => 'Недостаточно данных для авторизации',
    ];
}
```

**3. Ошибки REST API:**
```php
$result = $bitrix24Client->call('crm.lead.list', [], $authContext);

if (!empty($result['error'])) {
    // Логирование ошибки
    $appLogger->error('Bitrix24 REST API error', [
        'method' => 'crm.lead.list',
        'error' => $result['error'],
        'error_information' => $result['error_information'],
    ]);
    
    // Возврат ошибки клиенту
    return [
        'status' => 'error',
        'error_message' => $result['error_information'],
    ];
}
```

### Сохранение контекста между запросами

**Механизм:**
1. Первый запрос получает параметры из URL (`$_REQUEST`)
2. Параметры сохраняются в `$_SESSION`
3. Последующие запросы используют данные из сессии, если параметры не переданы

**Код:**
```php
// RequestContextService
public function getContext(): array
{
    $context = [];
    
    foreach (self::CONTEXT_KEYS as $key) {
        // Чтение из запроса
        $value = $this->sanitizeRequestValue($this->request[$key] ?? '');
        
        // Сохранение в сессию
        if ($value !== '') {
            $this->session[$key] = $value;
        }
        
        // Использование значения из запроса или сессии
        $context[$key] = $value !== '' 
            ? $value 
            : $this->sanitizeRequestValue($this->session[$key] ?? '');
    }
    
    return $context;
}
```

---

## Заключение

Приложение работает как **REST-приложение внутри Bitrix24 через iframe**, используя:

1. **Параметры запроса** от Bitrix24 для авторизации (AUTH_ID, DOMAIN)
2. **Bitrix24 SDK (BX24)** для UI-взаимодействий
3. **Bitrix24 REST API** для работы с данными портала
4. **Сессии PHP** для сохранения контекста между запросами
5. **Vue.js** для интерактивного интерфейса

Все взаимодействия с Bitrix24 происходят через безопасные механизмы авторизации и API, обеспечивая интеграцию приложения с экосистемой Bitrix24.

---

## Связанные документы

- [API приложения](./../API-REFERENCES/app-api.md)
- [Архитектура технологического стека](./../ARCHITECTURE/tech-stack.md)
- [Bitrix24 UI Kit](./../API-REFERENCES/bitrix24-ui-kit.md)
