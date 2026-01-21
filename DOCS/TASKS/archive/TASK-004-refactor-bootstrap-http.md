# TASK-004: Рефактор Bootstrap/HTTP слоя

Дата: 2026-01-14 23:35 (UTC+3, Брест)
Статус: draft
Приоритет: high
Исполнитель: Backend PHP (Bitrix24 REST)

## Цель
Централизовать обработку контекста запроса и формирование JSON‑ответов для REST‑приложения, исключив дублирование логики в эндпоинтах.

## Контекст
Текущие эндпоинты (`app/index.php`, `app/api/ui-state.php`) содержат собственную логику нормализации входных данных и выдачи ответа. Это усложняет масштабирование API и повторное использование контекста.

## Требования
### Функциональные
- Вынести сбор контекста запроса/сессии в отдельный сервис.
- Вынести формирование JSON‑ответа и единую обработку ошибок для API.
- Сохранить обратную совместимость структуры ответа `ui-state`.

### Нефункциональные
- PSR‑12.
- Логирование нефатальных ошибок через `AddMessage2Log`/`error_log`.
- Без хранения секретов в коде.

## Границы работ
### Входит
- Контекст запроса (AUTH_ID, DOMAIN, PLACEMENT, IFRAME, B24_FRAME).
- Единая обёртка для JSON‑ответов (status, error_message).
- Обработка ситуаций "bootstrap output" без нарушения JSON‑формата.

### Не входит
- Изменение бизнес‑логики `AccessControlService` и `UserGreetingService`.
- Изменение контрактов REST‑методов Bitrix24.

## Модули/компоненты
- `app/index.php`
- `app/api/ui-state.php`
- Новый сервис: `app/Services/RequestContextService.php`
- Новый сервис: `app/Services/JsonResponseService.php`

## Зависимости (иерархия)
1. `app/config/app-access.php` — режимы доступа.
2. `app/Services/AccessControlService.php` — решения по доступу.
3. `app/Services/UserGreetingService.php` — получение UI состояния.

## Этапы
1. Спроектировать контракт массива контекста запроса (AUTH_ID, DOMAIN, PLACEMENT и др.).
2. Создать `RequestContextService`:
   - нормализация данных из `$_REQUEST` и `$_SESSION`;
   - безопасная очистка строк.
3. Создать `JsonResponseService`:
   - установка заголовков;
   - единый формат ошибок;
   - безопасное `json_encode`.
4. Обновить `app/api/ui-state.php` и `app/index.php` на использование сервисов.
5. Проверить соответствие текущему формату ответа.

## Контракт контекста (пример)
Ключи и ожидаемые типы:
- `AUTH_ID`: `string`
- `DOMAIN`: `string`
- `member_id`: `string`
- `PLACEMENT`: `string`
- `PLACEMENT_OPTIONS`: `string`
- `IFRAME`: `string|bool`
- `B24_FRAME`: `string|bool`

## Контракт JSON‑ответа (минимум)
- `status`: `ok|error`
- `error_message`: `string`
- `allowed`: `bool`

## API‑методы Bitrix24
Не используются напрямую.

## Технические требования
- Вводные данные валидируются и приводятся к строкам/булевым значениям.
- Любой вывод в JSON должен быть валидным и не содержать PHP‑warning.
- Ошибки bootstrap не должны ломать JSON‑ответ.

## Критерии приемки
- `ui-state` отдаёт валидный JSON при любых входных параметрах.
- Дублирование логики нормализации устранено.
- Встроенный и прямой контекст корректно передаётся в сервисы.
- В ответе всегда присутствуют ключи `status` и `error_message`.

## Пример (псевдокод)
```php
$contextService = new RequestContextService($_REQUEST, $_SESSION);
$context = $contextService->getContext();

$responseService = new JsonResponseService();
$responseService->send($payload);
```

## Тестирование
- Вызов `app/api/ui-state.php` без параметров.
- Вызов с `AUTH_ID`, `DOMAIN`, `PLACEMENT`.
- Проверка JSON‑ответа в embedded/direct сценариях.
- Симуляция лишнего вывода до JSON (bootstrap output) — ответ валиден.

## История изменений
- 2026-01-14 23:35 (UTC+3, Брест): создана задача.
- 2026-01-14 23:40 (UTC+3, Брест): добавлены границы, контракты и критерии.
- 2026-01-15 09:08 (UTC+3, Брест): выполнена работа по выносу контекста и JSON-ответов в сервисы (`RequestContextService`, `JsonResponseService`), обновлены `app/index.php` и `app/api/ui-state.php`.
