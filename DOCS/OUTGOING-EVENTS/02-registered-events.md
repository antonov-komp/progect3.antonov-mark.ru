 # Зарегистрированные события и карта обогащения
 
 Дата создания: 2026-01-23 18:38 (UTC+03:00, Брест)
 
 ## Источник истины
 Список закреплён в `DOCS/TASKS/TASK-014-07-event-registry.md`.
 
 ## События CRM
 - Сделки: `ONCRMDEALADD`, `ONCRMDEALUPDATE`, `ONCRMDEALDELETE`
 - Лиды: `ONCRMLEADADD`, `ONCRMLEADUPDATE`, `ONCRMLEADDELETE`
 - Контакты: `ONCRMCONTACTADD`, `ONCRMCONTACTUPDATE`, `ONCRMCONTACTDELETE`
 - Компании: `ONCRMCOMPANYADD`, `ONCRMCOMPANYUPDATE`, `ONCRMCOMPANYDELETE`
 
 ## События смарт‑процессов
 - `ONCRMITEMADD`
 - `ONCRMITEMUPDATE`
 - `ONCRMITEMDELETE`
 
 ## События задач
 - `ONTASKADD`
 - `ONTASKUPDATE`
 - `ONTASKDELETE`
- `ONTASKCOMMENTADD`
 
 ## Пользователи и проекты
 - Пользователи: `ONUSERADD`, `ONUSERUPDATE`, `ONUSERDELETE`
 - Проекты (группы): `SONET_GROUP_CREATE`, `SONET_GROUP_UPDATE`, `SONET_GROUP_DELETE`
 
 ## Пользовательские поля CRM
 - `ONCRMUSERFIELDADD`
 - `ONCRMUSERFIELDUPDATE`
 - `ONCRMUSERFIELDDELETE`
 
 ## Карта «event → REST метод»
 - `ONCRMDEAL*` → `crm.deal.get`
 - `ONCRMLEAD*` → `crm.lead.get`
 - `ONCRMCONTACT*` → `crm.contact.get`
 - `ONCRMCOMPANY*` → `crm.company.get`
 - `ONCRMITEM*` → `crm.item.get`
 - `ONTASK*` → `tasks.task.get`
 - `ONUSER*` → `user.get`
 - `SONET_GROUP_*` → `sonet_group.get`
 - `ONCRMUSERFIELD*` → `crm.userfield.list`
 
 ## Регистрация событий (REST API)
 - `event.bind` — регистрация обработчика  
   Документация: https://context7.com/bitrix24/rest/event.bind
 - `event.get` — проверка регистрации  
   Документация: https://context7.com/bitrix24/rest/event.get
 - `event.unbind` — удаление  
   Документация: https://context7.com/bitrix24/rest/event.unbind
 
 ## Изменения
- 2026-01-23 18:38 (UTC+03:00, Брест): создан документ со списком событий.
- 2026-01-23 18:38 (UTC+03:00, Брест): добавлено событие `ONTASKCOMMENTADD`.
