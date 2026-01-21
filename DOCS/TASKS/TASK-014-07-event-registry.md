# TASK-014-07: Перечень событий для регистрации и карта обогащения

Дата: 2026-01-15 16:45 (UTC+3, Брест)
Статус: draft
Приоритет: high
Исполнитель: Backend PHP (Bitrix24 REST)

## Цель
Зафиксировать список исходящих событий Bitrix24 для регистрации и карту обогащения по сущностям.

## Контекст
Нужно единообразно регистрировать события и понимать, какие REST‑методы используются для обогащения.

## События CRM (сделки/лиды/контакты/компании)
- `ONCRMDEALADD`, `ONCRMDEALUPDATE`, `ONCRMDEALDELETE`
- `ONCRMLEADADD`, `ONCRMLEADUPDATE`, `ONCRMLEADDELETE`
- `ONCRMCONTACTADD`, `ONCRMCONTACTUPDATE`, `ONCRMCONTACTDELETE`
- `ONCRMCOMPANYADD`, `ONCRMCOMPANYUPDATE`, `ONCRMCOMPANYDELETE`

## События смарт‑процессов
- `ONCRMITEMADD`
- `ONCRMITEMUPDATE`
- `ONCRMITEMDELETE`

## События задач
- `ONTASKADD`
- `ONTASKUPDATE`
- `ONTASKDELETE`

## Пользователи и проекты
- `ONUSERADD`, `ONUSERUPDATE`, `ONUSERDELETE`
- `SONET_GROUP_CREATE`, `SONET_GROUP_UPDATE`, `SONET_GROUP_DELETE`

## Пользовательские поля CRM
- `ONCRMUSERFIELDADD`
- `ONCRMUSERFIELUPDATE`
- `ONCRMUSERFIELDDELETE`

## Карта «event → метод обогащения»
- `ONCRMDEAL*` → `crm.deal.get`
- `ONCRMLEAD*` → `crm.lead.get`
- `ONCRMCONTACT*` → `crm.contact.get`
- `ONCRMCOMPANY*` → `crm.company.get`
- `ONCRMITEM*` → `crm.item.get`
- `ONTASK*` → `tasks.task.get`
- `ONUSER*` → `user.get`
- `SONET_GROUP_*` → `sonet_group.get`
- `ONCRMUSERFIELD*` → `crm.userfield.list`

## API-методы Bitrix24
- `event.bind` — регистрация обработчика события  
  Документация: https://context7.com/bitrix24/rest/event.bind
- `event.get` — проверка регистрации  
  Документация: https://context7.com/bitrix24/rest/event.get
- `event.unbind` — удаление  
  Документация: https://context7.com/bitrix24/rest/event.unbind

## Критерии приёмки
- Список событий согласован с бизнес‑логикой.
- Карта обогащения закреплена в документации.
- События зарегистрированы и проверены через `event.get`.
