# TASK-014-05: Обогащение данных и регламент событий

Дата: 2026-01-15 16:45 (UTC+3, Брест)
Статус: draft
Приоритет: high
Исполнитель: Backend PHP (Bitrix24 REST)

## Цель
Реализовать обогащение «сырых» событий через REST API Bitrix24 и зафиксировать регламент событий/методов.

## Контекст
Исходящие вебхуки приходят с минимальным набором данных. Для аналитики и интеграций нужна полная карточка сущностей и справочники (воронки, стадии, пользовательские поля).

## Требования
### Функциональные
- Извлекать ID сущности из raw-пакета.
- Получать полные данные сущности через REST.
- Подтягивать справочники/воронки/стадии.
- Сохранять результат в `enriched.json`.
- Вести карту соответствия «event → метод обогащения».
- Сохранять источник обогащения (`method`, `responseTimeMs`) в enriched.

### Нефункциональные
- Все вызовы REST логировать при ошибках.
- Не сохранять секреты в логах.
- Соблюдать лимиты REST API (rate limit).

## Сущности и REST-методы (минимум)
- Сделки: `crm.deal.get`  
  Документация: https://context7.com/bitrix24/rest/crm.deal.get
- Лиды: `crm.lead.get`  
  Документация: https://context7.com/bitrix24/rest/crm.lead.get
- Контакты: `crm.contact.get`  
  Документация: https://context7.com/bitrix24/rest/crm.contact.get
- Компании: `crm.company.get`  
  Документация: https://context7.com/bitrix24/rest/crm.company.get
- Смарт‑процессы (тип/категории): `crm.type.list`, `crm.category.list`  
  Документация: https://context7.com/bitrix24/rest/crm.type.list  
  Документация: https://context7.com/bitrix24/rest/crm.category.list
- Элементы смарт‑процессов: `crm.item.get`  
  Документация: https://context7.com/bitrix24/rest/crm.item.get
- Воронки продаж: `crm.category.list`  
  Документация: https://context7.com/bitrix24/rest/crm.category.list
- Стадии лидов: `crm.status.list`  
  Документация: https://context7.com/bitrix24/rest/crm.status.list
- Задачи: `tasks.task.get`  
  Документация: https://context7.com/bitrix24/rest/tasks.task.get
- Проекты (группы): `sonet_group.get`  
  Документация: https://context7.com/bitrix24/rest/sonet_group.get
- Пользователи: `user.get`  
  Документация: https://context7.com/bitrix24/rest/user.get
- Пользовательские поля CRM:
  - список: `crm.userfield.list`  
    Документация: https://context7.com/bitrix24/rest/crm.userfield.list
  - создание: `crm.userfield.add`  
    Документация: https://context7.com/bitrix24/rest/crm.userfield.add
  - удаление: `crm.userfield.delete`  
    Документация: https://context7.com/bitrix24/rest/crm.userfield.delete

## Карта «event → метод»
Пример (расширяется):
- `ONCRMDEALADD` → `crm.deal.get`
- `ONCRMDEALUPDATE` → `crm.deal.get`
- `ONCRMLEADADD` → `crm.lead.get`
- `ONCRMLEADUPDATE` → `crm.lead.get`
- `ONCRMCONTACTADD` → `crm.contact.get`
- `ONCRMCONTACTUPDATE` → `crm.contact.get`
- `ONCRMCOMPANYADD` → `crm.company.get`
- `ONCRMCOMPANYUPDATE` → `crm.company.get`
- `ONTASKADD` → `tasks.task.get`
- `ONTASKUPDATE` → `tasks.task.get`

## Извлечение entityId
- При наличии `data[FIELDS][ID]` использовать его.
- Если ID приходит в `data[ID]`, использовать его.
- При отсутствии ID — логировать и переводить задание в `failed`.

## Справочники и кэш
- Воронки и стадии можно кэшировать в `logs/dicts/` на 24 часа.
- При устаревшем кэше — обновлять через REST.

## Регистрация исходящих событий (manual steps)
- Зарегистрировать webhook на URL из `TASK-014-01`.
- Список событий согласовать с бизнес‑логикой.
- Проверить регистрацию через `event.get`.
- Зафиксировать список событий в отдельном файле (например, `logs/allowed-events/registered-events.json`).

## Критерии приёмки
- В `enriched.json` отражены полные данные сущностей.
- Обогащение не блокирует приём webhook.
- Карта «event → метод» документирована.
- В enriched сохраняется источник обогащения и время ответа.

## Тестирование
- Сгенерировать тестовое событие для каждой сущности.
- Проверить корректность `enriched.json`.
- Проверить обработку события без ID (должно уйти в failed).
