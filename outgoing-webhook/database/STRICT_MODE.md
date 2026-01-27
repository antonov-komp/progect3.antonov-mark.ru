# Строгий режим работы с БД

## Описание

Система настроена на работу **только через SQLite БД**. Fallback на файловую систему отключен по умолчанию.

## Конфигурация

В `config.local.php`:

```php
'DATABASE_TYPE' => 'sqlite',
'DATABASE_PATH' => __DIR__ . '/database/events.db',
'DATABASE_WAL_ENABLED' => 'true',
'DATABASE_FALLBACK_TO_FILES' => 'false', // Строгий режим: БД обязательна
```

## Поведение

### При `DATABASE_FALLBACK_TO_FILES = 'false'` (строгий режим):

- ✅ Все события записываются **только в БД**
- ✅ Все задания очереди хранятся **только в БД**
- ❌ При недоступности БД система возвращает HTTP 503
- ❌ Fallback на файлы **отключен**

### При `DATABASE_FALLBACK_TO_FILES = 'true'` (режим совместимости):

- ✅ При недоступности БД система автоматически переключается на файлы
- ⚠️ События могут записываться в файлы вместо БД

## Проверка работы

1. **Проверка процессора:**
   ```bash
   php outgoing-webhook/tools/check-which-processor.php
   ```

2. **Проверка событий в БД:**
   ```bash
   php outgoing-webhook/tools/check-events-in-db.php
   ```

3. **Проверка через веб:**
   ```
   https://progect3.antonov-mark.ru/outgoing-webhook/tools/check-processor.php
   ```

## Удаление старых файлов

Старые файлы событий из `logs/` были удалены:
- `logs/ONTASKADD/`
- `logs/ONTASKCOMMENTADD/`
- `logs/ONTASKDELETE/`
- `logs/ONTASKUPDATE/`
- `logs/activity-first.log`
- `logs/activity-first-metrics.log`

**Важно:** Файлы логов ошибок (`logs/errors/`) остаются для диагностики.

## Миграция

Если нужно включить fallback временно (например, для миграции):

1. Установите `DATABASE_FALLBACK_TO_FILES = 'true'`
2. Выполните миграцию данных
3. Верните `DATABASE_FALLBACK_TO_FILES = 'false'`
