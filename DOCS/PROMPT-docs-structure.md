# PROMPT: генерация структуры документации

Дата: 2026-01-14 21:50 (UTC+3, Брест)

## Роль
Ты — технический писатель и системный аналитик для Bitrix24 REST приложения.
Цель — создать полный каркас документации в папке `DOCS/` и наполнить его
минимально достатимым содержанием для старта проекта.

## Контекст проекта
- Это Bitrix24 REST приложение, уже есть стартовая страница.
- Документация ведется в `DOCS/`.
- Задачи ведутся по двухэтапной схеме:
  - Основные задачи живут в корне `DOCS/TASKS/`.
  - Завершенные задачи перемещаются в `DOCS/TASKS/archive/`.
  - Невыполнимые/заблокированные задачи перемещаются в `DOCS/TASKS/backlog/`.
- Даты указываются в формате `YYYY-MM-DD HH:MM (UTC+3, Брест)`.
- Секреты в документации маскируются.

## Ожидаемый результат
1. Создать структуру директорий:
   - `DOCS/ARCHITECTURE/`
   - `DOCS/TASKS/`
   - `DOCS/TASKS/archive/`
   - `DOCS/TASKS/backlog/`
   - `DOCS/PLAN/`
   - `DOCS/GUIDES/`
   - `DOCS/API-REFERENCES/`
   - `DOCS/DB-SCHEMA/`
   - `DOCS/SQL/original-queries/`
   - `DOCS/SQL/optimized/`
   - `DOCS/SQL/history/`

2. Создать и заполнить файлы:
   - `DOCS/README.md`
   - `DOCS/ARCHITECTURE/tech-stack.md`
   - `DOCS/ARCHITECTURE/data-model.md`
   - `DOCS/TASKS/README.md`
   - `DOCS/PLAN/README.md`
   - `DOCS/GUIDES/ui-standards.md`
   - `DOCS/API-REFERENCES/bitrix24-ui-kit.md`
   - `DOCS/DB-SCHEMA/tables.md`
   - `DOCS/SQL/original-queries/README.md`
   - `DOCS/SQL/optimized/README.md`
   - `DOCS/SQL/history/README.md`

## Требования к содержимому
- В каждом файле должна быть дата (формат Бреста).
- Использовать ссылки:
  - https://context7.com/bitrix24/rest/
  - https://apidocs.bitrix24.ru/
  - https://apidocs.bitrix24.ru/sdk/ui.html
- Для UI-гайдов: использовать `BX.UI.*`, `BX.ready()`, `BX.ajax()`.
- Для SQL: соблюдать шапку файлов (источник, дата, тип, описание).
- Для задач: фиксировать статусы и перемещение в `archive/backlog`.

## Минимальные шаблоны (обязательно)
### TASK-файл
```
# TASK-XXX: Название
Дата: 2026-01-14 21:50 (UTC+3, Брест)
Статус: draft | in_progress | done | blocked | failed
Приоритет: low | medium | high
Исполнитель: TBD

## Цель
...

## Контекст
...

## Требования
- ...

## Этапы
1. ...

## API-методы
- `method.name` — https://context7.com/bitrix24/rest/method.name

## Критерии приемки
- ...

## Тестирование
- ...

## История изменений
- ...
```

### SQL (исходный)
```
-- Источник: путь/к/файлу::метод()
-- Дата: 2026-01-14 21:50 (UTC+3, Брест)
-- Тип: SELECT | INSERT | UPDATE | DELETE | JOIN | AGGREGATE
-- Описание: краткое назначение запроса
```

### SQL (оптимизированный)
```
-- Оптимизировано: 2026-01-14 21:50 (UTC+3, Брест)
-- Причина: ...
-- Ссылка на задачу: TASK-XXX
```

## Проверка результата
- В `DOCS/` есть все указанные папки.
- В `DOCS/` есть все перечисленные файлы.
- В файлах указаны даты и ссылки на документацию.
- В `DOCS/TASKS/README.md` описан процесс archive/backlog.

## Стиль
- Язык: русский.
- Кратко, структурированно.
- Без лишней воды.
