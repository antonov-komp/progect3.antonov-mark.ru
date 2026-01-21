# Логи Bitrix24 REST (CRest и Bitrix24Client)

## Источник и код

- Клиент REST: `app/crest.php` (CRest).
- Обертка клиента: `app/Services/Bitrix24Client.php`.

## Где лежат

- Базовый каталог: `/var/www/progect3.antonov-mark.ru/app/logs/`.
- Иерархия: `YYYY-MM-DD/HH/<timestamp>_<type>_<rand>log.json`.
- Пример: `app/logs/2026-01-21/11/1768983400_callCurl_6439867log.json`.

## Что пишется

CRest логирует каждый вызов REST (если логирование не отключено):

- URL запроса
- параметры запроса
- curl info
- ответ (result/error)

Тип файла определяется вторым сегментом имени (`callCurl`, `installApp`, `exceptionCurl`, `emptySetting`).

## Настройки логирования

Файл настроек: `app/settings.php`.

- `C_REST_LOGS_DIR` — путь к каталогу логов CRest.
- `C_REST_BLOCK_LOG` — отключить логирование CRest (если `true`).
- `C_REST_LOG_TYPE_DUMP` — логирование в var_export-формате (если `true`).

Важно: значения `C_REST_CLIENT_ID` и `C_REST_CLIENT_SECRET` являются секретами и не должны попадать в документацию или логи.

## Логи ошибок Bitrix24Client

`app/Services/Bitrix24Client.php` пишет ошибки через:

- `AddMessage2Log(...)` при наличии Bitrix API,
- либо `error_log(...)` как fallback.

Это не CRest-логи, а системные записи об ошибках REST, например:

- проблемы curl
- невалидный JSON
- ошибки Bitrix24 API (`error`, `error_information`)

## Когда смотреть

- Любые ошибки REST-запросов к Bitrix24.
- Проверка корректности параметров запросов и ответов.
- Диагностика истечения токена и проблем OAuth.
