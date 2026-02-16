# Ошибка ERROR_CORE / HTTP 400 при создании поля-встройки

## Симптом

При создании поля-встройки (тип «С кнопками» и др.) в смарт-процессе или сделке/лиде появляется ошибка **«HTTP 400»** или **«ERROR_CORE»**.

## Причина

Bitrix24 REST API отклоняет вызов метода **userfieldtype.add** — регистрация кастомного типа поля не выполняется.

Типичные причины:

1. **Нет scope для userfieldtype** — приложение не имеет прав на методы виджетов (userfieldtype).
2. **HANDLER в другом домене** — URL handler'а должен быть на том же домене, что и приложение.
3. **Приложение не переустановлено** — после изменения scope нужно переустановить приложение.

## Решение

### 1. Добавить scope в настройках приложения

1. Зайдите в [oauth.bitrix.info](https://oauth.bitrix.info/) (личный кабинет разработчика Bitrix24).
2. Найдите приложение (Client ID: `local.69708acb247977.45840681` или ваш).
3. В настройках приложения проверьте список **Scope (права)**.
4. Добавьте при необходимости scope **userfieldconfig** и права, связанные с виджетами.
5. Сохраните изменения.

### 2. Переустановить приложение в Bitrix24

После изменения scope:

1. Удалите приложение из портала Bitrix24 (Настройки → Разработчикам → Приложения).
2. Установите приложение заново через ссылку установки.
3. Подтвердите новые права при установке.

### 3. Проверить HANDLER

URL handler'а формируется автоматически: `https://progect3.antonov-mark.ru/embed/buttons-view.php`

- Домен handler'а должен совпадать с доменом приложения.
- Должен использоваться **HTTPS** (для продакшена).

## Проверка scope

Выполните на сервере:

```bash
# Установите временный webhook в Bitrix24 и вызовите scope
# Или проверьте через приложение: при установке Bitrix24 показывает запрашиваемые права
```

При установке приложения Bitrix24 отображает список запрашиваемых прав. Убедитесь, что среди них есть права на управление пользовательскими полями CRM.

## Логи

Ошибки Bitrix24 REST логируются в:

- `app/logs/` (CRest)
- `/var/www/progect3.antonov-mark.ru/logs/php_error.log`

Пример записи:

```
bitrix24 rest error {"method":"userfieldtype.add","source":"token","error":"ERROR_CORE","error_information":"HTTP 400"}
```

## Ссылки

- [userfieldtype.add](https://apidocs.bitrix24.ru/api-reference/widgets/user-field/userfieldtype-add.html)
- [Пользовательские поля CRM](https://apidocs.bitrix24.ru/api-reference/crm/universal/user-defined-fields/userfield-type.html)
