# Проблема: Прямой доступ при deny_direct = true

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

---

## Описание проблемы

При открытии приложения напрямую (минуя Bitrix24) с настройкой `deny_direct => true` в конфигурации, пользователь видит:

- Сообщение: "Откройте приложение внутри Bitrix24, чтобы определить пользователя."
- В контексте: "Доступ: allow | Контекст: неизвестен"

**Ожидаемое поведение:**
- Доступ должен быть запрещён (`allowed: false`)
- Причина: `deny_direct`
- Сообщение: "Прямой доступ запрещен."

---

## Анализ логики доступа

### Текущая конфигурация

```php
// app/config/app-access.php
return array (
  'global_enabled' => true,
  'deny_direct' => true,  // ← Прямой доступ запрещён
  'super_admin_id' => '1619',
  'allowed_users' => array (),
  'allowed_departments' => array (),
);
```

### Алгоритм проверки доступа

**Файл:** `app/Services/AccessControlService.php`

**Логика при прямом доступе:**

```php
// 1. Определение контекста
$accessContext = 'unknown' или 'direct';  // При прямом доступе
$isDirectContext = true;  // Если accessContext === 'direct' || 'unknown'

// 2. Проверка супер-администратора
$userId = '';  // При прямом доступе нет AUTH_ID, профиль не загружается
$isSuperAdmin = false;  // userId пустой, проверка не проходит

// 3. Проверка глобального доступа
$globalEnabled = true;  // Доступ включен

// 4. Проверка прямого доступа
if ($isDirectContext) {
    $decision = $denyDirect === true ? 'deny' : 'allow';
    $reason = $denyDirect === true ? 'deny_direct' : 'direct_allowed';
}

// Результат при deny_direct = true:
// decision = 'deny'
// reason = 'deny_direct'
// allowed = false
```

### Проблема

**Возможные причины:**

1. **Кеширование данных на фронтенде**
   - Старые данные из `uiStateStore` могут показывать `allowed: true`
   - Нужно обновить состояние через `reload()`

2. **Ошибка в определении контекста**
   - `AccessContextService` может неправильно определять контекст как `unknown` вместо `direct`
   - При `unknown` логика может работать иначе

3. **Специальная логика для unknown контекста**
   - Строка 68-70 в `AccessControlService.php`:
     ```php
     if ($isDirectContext && $reason === 'direct_allowed' && $superAdminId !== '') {
         $isSuperAdmin = true;
     }
     ```
   - Это не влияет на наш случай, так как `reason = 'deny_direct'`

4. **Проблема с получением профиля пользователя**
   - При прямом доступе нет `AUTH_ID` и `DOMAIN`
   - `BitrixUserProfileService::fetchProfile()` может возвращать пустой профиль
   - Но это не должно влиять на логику доступа

---

## Ожидаемое поведение

### При `deny_direct = true` и прямом доступе:

**Backend должен вернуть:**
```json
{
  "allowed": false,
  "deny_message": "Прямой доступ запрещен.",
  "access": {
    "context": "unknown",  // или "direct"
    "is_embedded": false,
    "decision": "deny",
    "reason": "deny_direct"
  }
}
```

**Frontend должен показать:**
- Компонент `AccessDenied` с сообщением "Прямой доступ запрещен."
- НЕ показывать предупреждение "Откройте приложение внутри Bitrix24..."

### Текущее поведение (проблема):

**Frontend показывает:**
- "Доступ: allow" (неправильно!)
- "Контекст: неизвестен"
- Предупреждение "Откройте приложение внутри Bitrix24..."

---

## Решение

### Проверка 1: Обновление состояния

Убедитесь, что состояние обновляется при загрузке:

```javascript
// frontend/src/views/AppView.vue
const { state, loading, error, reload } = useUiState();
reload();  // ← Должно вызываться при монтировании
```

### Проверка 2: Логика отображения

**Файл:** `frontend/src/views/HomeView.vue`

```javascript
const showEmbedWarning = computed(
  () => state.value.access.context === 'direct' || 
        state.value.access.is_embedded === false,
);
```

**Проблема:** Предупреждение показывается даже когда доступ запрещён.

**Решение:** Показывать предупреждение только если доступ разрешён:

```javascript
const showEmbedWarning = computed(
  () => state.value.allowed && (
    state.value.access.context === 'direct' || 
    state.value.access.is_embedded === false
  ),
);
```

### Проверка 3: Проверка API ответа

Проверьте, что API действительно возвращает `allowed: false`:

```bash
# Откройте приложение напрямую
curl "https://progect3.antonov-mark.ru/api/ui-state.php"

# Должен вернуть:
{
  "allowed": false,
  "deny_message": "Прямой доступ запрещен.",
  ...
}
```

---

## Рекомендации

### 1. Исправить логику отображения предупреждения

**Файл:** `frontend/src/views/HomeView.vue`

```javascript
const showEmbedWarning = computed(() => {
  // Показывать предупреждение только если:
  // 1. Доступ разрешён
  // 2. Контекст прямой или неизвестен
  return (
    state.value.allowed &&
    (state.value.access.context === 'direct' || 
     state.value.access.context === 'unknown' ||
     state.value.access.is_embedded === false)
  );
});
```

### 2. Добавить логирование для отладки

**Файл:** `app/Services/AccessControlService.php`

Добавить логирование при прямом доступе:

```php
if ($isDirectContext) {
    $decision = $denyDirect === true ? 'deny' : 'allow';
    $reason = $denyDirect === true ? 'deny_direct' : 'direct_allowed';
    
    // Логирование для отладки
    $this->logger->log('access-debug', [
        'is_direct_context' => $isDirectContext,
        'deny_direct' => $denyDirect,
        'decision' => $decision,
        'reason' => $reason,
        'access_context' => $accessContext,
    ]);
}
```

### 3. Проверить кеширование

Убедитесь, что состояние не кешируется между запросами:

```javascript
// frontend/src/composables/useUiState.js
// Убедитесь, что состояние обновляется при каждом запросе
```

---

## Вывод

**Верно ли так работает?**

**НЕТ!** При `deny_direct = true` и прямом доступе:

1. ✅ Backend должен вернуть `allowed: false` с `reason: 'deny_direct'`
2. ❌ Frontend показывает "Доступ: allow" (неправильно!)
3. ❌ Предупреждение показывается даже при запрещённом доступе

**Проблема:** Логика отображения на фронтенде не учитывает, что доступ может быть запрещён при прямом доступе.

**Решение:** Исправить условие показа предупреждения в `HomeView.vue`, чтобы оно показывалось только при разрешённом доступе.

---

## Связанные документы

- [Конфигурация доступа к приложению](./access-configuration.md)
- [Интеграция с Bitrix24 через iframe](./bitrix24-iframe-integration.md)
