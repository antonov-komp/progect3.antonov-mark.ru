# TASK-015-02: Этап 2 — Backend доступ и API

**Дата:** 2026-01-15 23:17 (UTC+03:00, Brest)  
**Статус:** In Progress  
**Приоритет:** High  
**Ссылка на основную задачу:** `DOCS/TASKS/TASK-015-access-module-permissions.md`

## Цель этапа
Обновить backend-логику доступа и API с учетом согласованных требований.

## Задачи этапа
1. **AccessControlService**
   - Добавить правило: супер-админ допускается даже при `deny_direct=true`.
   - Сохранить причину доступа в `access_reason` (например, `super_admin_override`).
2. **AccessConfigService**
   - Поддержать сохранение конфигурации только по явному подтверждению (backend готов принимать POST по кнопке).
   - Подтвердить нормализацию `deny_direct`.
3. **AccessDirectoryService**
   - Добавить кеширование списка пользователей/отделов (TTL 30–60 минут).
   - Ограничить вывод до 200 элементов (или зафиксировать пагинацию, если потребуется).
4. **Логирование**
   - Внести частичное маскирование ПДн в логах (ID/имя) согласно требованиям.
   - Убедиться, что ошибки Bitrix24 логируются по категориям.
5. **API-ответы**
   - При ошибке профиля возвращать `status=error`, но UI разрешать отобразить сообщение.

## Файлы и компоненты
- `app/Services/AccessControlService.php`
- `app/Services/AccessConfigService.php`
- `app/Services/AccessDirectoryService.php`
- `app/Services/BitrixUserProfileService.php`
- `app/Services/AppLogger.php`
- `app/api/access-config.php`
- `app/api/access-directory.php`
- `app/api/ui-state.php`
- `app/config/app-access.php`

## Детализация изменений
1. **AccessControlService**
   - Добавить явный приоритет супер-админа над `deny_direct`.
   - Логировать `access_reason=super_admin_override` при обходе запрета.
2. **AccessDirectoryService**
   - Встроить кеш (например, file-cache/array-cache) с TTL 30–60 мин.
   - Возвращать признак ограничения списка, если обрезка до 200.
3. **AccessConfigService**
   - Сохранять конфиг только при POST (счетчик/флаг ручного сохранения).
4. **JsonResponseService**
   - Стабильные поля в ответе (`status`, `error_message`, `config_error`).
5. **Bitrix24Client**
   - Убедиться, что ошибки `expired_token`, `QUERY_LIMIT_EXCEEDED`, `NO_AUTH_FOUND` логируются как отдельные причины.

## API-методы Bitrix24 (ссылки)
- `user.current`: https://context7.com/bitrix24/rest/user.current
- `user.get`: https://context7.com/bitrix24/rest/user.get
- `user.admin`: https://context7.com/bitrix24/rest/user.admin
- `department.get`: https://context7.com/bitrix24/rest/department.get

## Зависимости
- Этап 1 (согласование требований).

## Критерии завершения
- Backend поддерживает `deny_direct` с исключением для супер-админа.
- Кеш справочников включен и документирован.
- Логи маскируют ПДн частично.
- Контракт API соответствует обновленному `TASK-015`.

## Выполнено
**Дата:** 2026-01-15 23:25 (UTC+03:00, Brest)  
- `AccessConfigService`: нормализация конфига и лимит списков ID до 200.
- `AccessDirectoryService`: файловый кеш справочников пользователей/отделов (TTL 60 минут).
- `AccessControlService`: маскирование ID/имени в логах отказов.

## Не выполнено
- Приоритет супер-админа над `deny_direct` (исключение для direct‑контекста).
- Логирование `access_reason=super_admin_override` при обходе запрета.
- Явный признак ограничения списка до 200 в ответе справочников.
- Явная категоризация ошибок Bitrix24 в `Bitrix24Client` (expired_token, rate‑limit, NO_AUTH_FOUND).

## Примечания по безопасности
- Секреты и токены не пишем в лог в открытом виде.
- Входные данные валидируются, пустые/невалидные ID игнорируются.

