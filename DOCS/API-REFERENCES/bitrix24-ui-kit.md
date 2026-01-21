# Bitrix24 UI Kit

Дата: 2026-01-14 22:38 (UTC+3, Брест)
Обновлено: 2026-01-14 23:21 (UTC+3, Брест)

## Документация
- https://apidocs.bitrix24.ru/sdk/ui.html
- https://context7.com/bitrix24/

## Базовые компоненты
- `BX.UI.Button` — кнопки.
- `BX.UI.Input` — поля ввода.
- `BX.UI.Select` — списки.
- `BX.UI.Alert` — уведомления.

## Примечания по использованию
- Инициализация через `BX.ready()`.
- Для асинхронных операций использовать `BX.ajax()`.
- Не смешивать UI Kit с внешними CSS-фреймворками.
 - Для Vue UI: инициализировать `BX24` перед загрузкой состояния.

## Уведомления в Vue-приложении
- Проверять наличие `BX.UI.Notification.Center`.
- Вызов: `BX.UI.Notification.Center.notify({ content: '...' })`.
- Использовать для нейтральных сообщений об ошибках API.

## Auth-контекст для Vue
- Получать данные через `BX24.getAuth()` (если доступен).
- Пробрасывать `AUTH_ID`, `DOMAIN`, `member_id` в `/api/ui-state.php`.

## Мини-пример
```
BX.ready(function () {
  const input = new BX.UI.Input({
    placeholder: 'Имя клиента',
  });
  input.renderTo(document.body);
});
```

## Изменения
- 2026-01-14 23:21 (UTC+3, Брест): добавлены правила Vue и auth-контекста.
