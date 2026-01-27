# Проверка трекинга сделок и сохранения в БД

Краткий чек-лист: как убедиться, что приём событий сделок, снимки и изменения полей работают и пишутся в БД.

---

## 1. Подготовка

- **Токен** в `outgoing-webhook/config.local.php`: `OUTGOING_WEBHOOK_TOKEN`.
- **БД**: `DATABASE_TYPE` = `sqlite`, `DATABASE_PATH` указывает на `events.db`.
- **Доступ к Bitrix24 REST** для `crm.deal.get` (те же учётные данные, что в приложении).
- **URL вебхука**: например `https://your-domain/outgoing-webhook/index.php` (без `/public/` в пути).

---

## 2. Инициализация БД

```bash
cd /var/www/progect3.antonov-mark.ru
php outgoing-webhook/tools/init-database.php
```

Должны создаться таблицы, в т.ч. `entity_states` и `entity_field_changes`. В выводе — «Схема БД успешно инициализирована».

---

## 3. Автоматическая проверка (рекомендуется)

```bash
php outgoing-webhook/tools/verify-deal-tracking.php --deal-id=13177 --endpoint="https://progect3.antonov-mark.ru/outgoing-webhook/index.php"
```

Скрипт проверяет конфиг, БД, отправляет тестовые события `ONCRMDEALADD` и `ONCRMDEALUPDATE`, затем смотрит записи в `entity_states` и `entity_field_changes`. `--deal-id` — ID реальной сделки из вашего Bitrix24 (иначе `crm.deal.get` может вернуть пусто).

**Без HTTP** (только конфиг и БД):

```bash
php outgoing-webhook/tools/verify-deal-tracking.php --skip-http
```

---

## 4. Ручная проверка

### 4.1. Статус БД и таблиц

```bash
php outgoing-webhook/tools/check-db-stats.php
```

Проверьте блоки **«Снимки сущностей (entity_states)»** и **«Изменения полей (entity_field_changes)»**. После тестов по сделкам там должны быть записи.

### 4.2. Тест событий сделок

```bash
php outgoing-webhook/tools/test-token-events.php \
  --event=ONCRMDEALADD \
  --deal-id=13177 \
  --endpoint="https://progect3.antonov-mark.ru/outgoing-webhook/index.php"
```

Ожидается `"status":200` в JSON. Затем то же для `ONCRMDEALUPDATE`:

```bash
php outgoing-webhook/tools/test-token-events.php \
  --event=ONCRMDEALUPDATE \
  --deal-id=13177 \
  --endpoint="https://progect3.antonov-mark.ru/outgoing-webhook/index.php"
```

### 4.3. Повторный просмотр статистики

Снова выполните `check-db-stats.php`. Должна появиться или обновиться запись по сделке `13177` в `entity_states`. Записи в `entity_field_changes` появляются только когда между снимками реально изменились поля сделки.

### 4.4. Пользовательское представление полей сделки (details_resolved)

В `deal_details` хранится `details_resolved` — массив `[{code, title, type, raw, display}, ...]` с человеческими названиями полей, типами и отображаемыми значениями (стадии, категории, списки). Просмотр:

```bash
php outgoing-webhook/tools/show-deal-resolved.php --deal-id=13177 --limit=1
```

Перед первым использованием выполните миграцию: `php outgoing-webhook/tools/run-migrations.php`.

### 4.5. Изменения полей (entity_field_changes) и пользовательские значения

В `entity_field_changes` хранятся сырые изменения `changes` и при наличии — `changes_resolved` (названия полей, расшифровка списков: «Да» вместо `237`, «2. Взято в работу» вместо `C1:PREPARATION` и т.д.). Просмотр:

```bash
php outgoing-webhook/tools/check-deal-changes.php --deal-id=13177
```

Вывод использует `changes_resolved`, если колонка есть и заполнена; иначе — сырые `changes`. Миграция: `run-migrations.php` (003 добавляет `changes_resolved`).

**Обратное заполнение** уже существующих записей без `changes_resolved`:

```bash
php outgoing-webhook/tools/backfill-field-changes-resolved.php [--deal-id=13177] [--dry-run]
```

### 4.6. Проверка реальных изменений «что было → что стало»

1. В Bitrix24 откройте сделку с известным ID (например, 13177).
2. Измените любое поле (название, этап, сумму, UF_* и т.п.) и сохраните.
3. Убедитесь, что исходящий вебхук с `ONCRMDEALUPDATE` настроен и уходит на ваш `index.php`.
4. После срабатывания вебхука выполните `check-deal-changes.php --deal-id=13177` — появятся новые строки в `entity_field_changes`, при наличии резолвера будут выведены пользовательские значения.

---

## 5. Типичные проблемы

| Симптом | Что проверить |
|--------|----------------|
| 404 на вебхук | URL без `/public/`, путь `.../outgoing-webhook/index.php`. |
| 403 | `OUTGOING_WEBHOOK_TOKEN` в конфиге и в настройках вебхука в Bitrix24 совпадают; при наличии `OUTGOING_WEBHOOK_ALLOWED_IPS` — ваш IP разрешён. |
| Нет записей в `entity_states` | `DATABASE_TYPE=sqlite`, БД создана (`init-database.php`), PHP имеет права на запись в файл БД и директорию. |
| Пустой ответ `crm.deal.get` | `--deal-id` указывает на существующую сделку; учётные данные REST приложения имеют доступ к CRM. |

---

## 6. Дальнейшие шаги

- Сузить список полей для сравнения (только этап, сумма, UF_* и т.д.).
- Вынести отбор по сделкам в отчёт или дашборд.
- Подключить обработку очереди из БД (`process-queue` → `queue_jobs`), если нужна обработка сделок через очередь.

См. также `26-deal-vs-task-levels.md` и `12-state-tracking.md`.
