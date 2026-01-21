# TASK-005: Рефактор сервисного слоя (разделение обязанностей)

Дата: 2026-01-14 23:35 (UTC+3, Брест)
Статус: done
Приоритет: high
Исполнитель: Backend PHP (Bitrix24 REST)

## Цель
Разделить обязанности текущего `UserGreetingService` на несколько специализированных сервисов, упростить тестирование и сопровождение логики UI‑состояния.

## Контекст
`UserGreetingService` содержит всё: контекст доступа, REST‑вызовы, сбор данных, построение приветствия и логирование. Это усложняет развитие и контроль ошибок.

## Требования
### Функциональные
- Вынести вычисление контекста доступа в отдельный сервис.
- Вынести работу с профилем пользователя Bitrix24 в отдельный сервис.
- Вынести построение приветствия и сообщений контекста в отдельный сервис.
- Сохранить текущий формат `ui-state` и логику `AccessControlService`.

### Нефункциональные
- PSR‑12.
- Логирование через единый механизм (подготовка к TASK‑007).
- Обработка ошибок REST не прерывает основной сценарий.

## Границы работ
### Входит
- Декомпозиция `UserGreetingService` на сервисы.
- Определение контрактов DTO для `user`, `access`, `auth`.
- Сохранение структуры `ui-state`.

### Не входит
- Переписывание `AccessControlService` логики доступа (кроме интеграции сервисов).
- Изменение REST‑клиента (это TASK‑006).

## Модули/компоненты
- `app/Services/UserGreetingService.php`
- `app/Services/AccessControlService.php`
- Новые сервисы (предложение):
  - `app/Services/AccessContextService.php`
  - `app/Services/BitrixUserProfileService.php`
  - `app/Services/GreetingComposerService.php`

## Зависимости (иерархия)
1. `app/api/ui-state.php` — потребитель итогового `ui-state`.
2. `app/Services/AccessControlService.php` — точка принятия решения о доступе.
3. `app/crest.php` — REST‑клиент (до внедрения общего клиента в TASK‑006).

## Этапы
1. Спроектировать контракты данных между сервисами (массивы с ключами `user`, `access`, `auth`).
2. Реализовать `AccessContextService`:
   - определение embedded/direct;
   - fallback для unknown.
3. Реализовать `BitrixUserProfileService`:
   - получение `user.current`;
   - расширение через `user.get`, `department.get`, `user.admin`.
4. Реализовать `GreetingComposerService`:
   - построение приветствия;
   - генерация текста контекста.
5. Обновить `UserGreetingService` (или заменить его фасадом).

## Контракты данных (минимум)
`user`:
- `id`: `string`
- `name`: `string`
- `last_name`: `string`
- `is_admin`: `bool|null`
- `department`: `string`

`access`:
- `context`: `embedded|direct|unknown`
- `is_embedded`: `bool`
- `mode`: `string`
- `decision`: `allow|deny`
- `reason`: `string`

`auth`:
- `source`: `app_token|request_token`

## API‑методы Bitrix24
- `user.current` — https://context7.com/bitrix24/rest/user.current
- `user.get` — https://context7.com/bitrix24/rest/user.get
- `department.get` — https://context7.com/bitrix24/rest/department.get
- `user.admin` — https://context7.com/bitrix24/rest/user.admin

## Технические требования
- Все входные данные валидируются и нормализуются.
- Ошибки REST логируются, но не прерывают формирование `ui-state`.
- В DTO/массивах описать ключи и допустимые значения.
- Не допускать прямых вызовов `CRest` из UI‑компонентов (только сервисы).

## Критерии приемки
- `ui-state` соответствует текущему контракту.
- Каждый сервис отвечает только за свою часть логики.
- Отсутствуют дублирующиеся вычисления контекста доступа.
- Сервисная декомпозиция покрывает все ветки `user.current`, `user.get`, `user.admin`.

## Пример (структура данных)
```php
$uiState = [
    'greeting' => 'Привет, ...',
    'context_message' => 'Администратор портала: ...',
    'user' => ['name' => '', 'last_name' => '', 'is_admin' => null],
    'access' => ['context' => 'embedded', 'decision' => 'allow'],
];
```

## Тестирование
- Проверка `ui-state` для embedded/direct/unknown.
- Сценарии без `AUTH_ID` и без `DOMAIN`.
- Ошибки REST не ломают JSON‑ответ.
- Проверка fallback при отсутствии `user_id`.

## Отметка о выполнении
- 2026-01-15 09:13 (UTC+3, Брест): сервисы разнесены на `AccessContextService`,
  `BitrixUserProfileService`, `GreetingComposerService`; обновлен фасад
  `UserGreetingService` и подключение в `app/api/ui-state.php`.

## История изменений
- 2026-01-14 23:35 (UTC+3, Брест): создана задача.
- 2026-01-14 23:40 (UTC+3, Брест): добавлены контракты данных и границы.
- 2026-01-15 09:13 (UTC+3, Брест): выполнение задачи и фиксация результата.
