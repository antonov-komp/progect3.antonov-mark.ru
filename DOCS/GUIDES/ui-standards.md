# UI-стандарты

Дата: 2026-01-14 22:38 (UTC+3, Брест)
Обновлено: 2026-01-14 23:29 (UTC+3, Брест)

## Источники
- https://apidocs.bitrix24.ru/sdk/ui.html
- https://context7.com/bitrix24/ (раздел "Дизайн")

## Базовые правила
- Для встроенных интерфейсов использовать компоненты `BX.UI.*`.
- Инициализация скриптов через `BX.ready()`.
- AJAX-запросы через `BX.ajax()`.
- Для внешних интерфейсов: стилизация в духе Bitrix24, шрифт Roboto.
- Для Vue UI использовать backend JSON (запрещены прямые REST-вызовы Bitrix24).

## Типографика и сетка
- Шрифт: Roboto или системный шрифт Bitrix24.
- Базовый размер: 14px, высота строки 1.5.
- Отступы: шаг 8px (модульная сетка).

## Компоненты
- Кнопки: `BX.UI.Button`.
- Поля ввода: `BX.UI.Input`.
- Списки: `BX.UI.Select`.
- Уведомления: `BX.UI.Alert` или `BX.UI.Notification`.

## Vue UI: структура компонентов
- `AppShell.vue` — контейнер экрана и базовая карточка.
- `GreetingMessage.vue` — приветствие пользователя.
- `AccessContextInfo.vue` — контекст (админ, тип доступа, отдел).
- `AccessDenied.vue` — сообщение при запрете доступа.

## Правила отображения
- `loading=true` → показывается индикатор загрузки.
- `allowed=false` → показывается `AccessDenied`, контент скрыт.
- `status=error` → нейтральное сообщение + уведомление через `BX.UI.Notification`.
- `auth.source=app_token` → предупреждение о необходимости открыть в Bitrix24.

## Доступность
- Использовать семантические теги (`button`, `label`).
- Добавлять `aria-label` для интерактивных элементов.
- Проверять контрастность.

## Структура UI-файлов
- Vue-проект: `frontend/` (Vite), сборка в `public/vue/`.
- Компоненты: `frontend/src/components/`, экраны: `frontend/src/views/`.
- Логика: `frontend/src/composables/`, API-клиенты: `frontend/src/services/`.
- Общие стили: `frontend/src/styles/`, подключаются из `frontend/src/app/main.js`.
- Встроенные виджеты без Vue: отдельные JS-файлы по экрану.

## Vue 3 для встроенного UI
- Инициализация: `BX.ready()` при наличии BX.
- Уведомления: `BX.UI.Notification.Center.notify`.
- Запрет на прямые REST-вызовы Bitrix24 с фронта — только backend JSON.
- Получение auth-контекста через `BX24.getAuth()` перед запросом UI.

## Поток данных UI (Vue)
1. `app/index.php` публикует `window.APP_REQUEST_CONTEXT`.
2. `frontend/src/services/apiClient.js` формирует запрос к `/api/ui-state.php`.
3. `frontend/src/composables/useUiState.js` нормализует ответ и уведомляет об ошибках.
4. `frontend/src/views/AppView.vue` отображает доступ, приветствие и контекст.

## CSS и нейминг
- Общие стили: `frontend/src/styles/app.css`.
- Префикс классов: `app-` (структура) и `b24-` (визуальные токены).
- Отступы и типографика соответствуют сетке 8px.
- Основные b24‑классы: `b24-card`, `b24-alert`, `b24-text-muted`.

## Обработка ошибок UI
- Ошибки API показываются нейтрально (`error_message`).
- Дополнительно уведомления через `BX.UI.Notification` при доступности BX.
- Технические детали (stacktrace, HTTP-коды) не выводятся в UI.

## Тест‑чеклист
- См. `DOCS/GUIDES/vue-test-checklist.md`.

## Пример (встроенный виджет)
```
BX.ready(function () {
  const button = new BX.UI.Button({
    text: 'Сохранить',
    color: BX.UI.Button.Color.PRIMARY,
    onclick: function () {
      BX.UI.Alert.show('Готово');
    },
  });
  button.renderTo(document.body);
});
```

## Изменения
- 2026-01-14 23:21 (UTC+3, Брест): добавлен поток данных Vue и правила auth.
- 2026-01-14 23:29 (UTC+3, Брест): детализированы компоненты и правила UI.
- 2026-01-14 23:47 (UTC+3, Брест): добавлены b24‑классы и ссылки на тест‑чеклист.
