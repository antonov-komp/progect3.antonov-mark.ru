#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/progect3.antonov-mark.ru"
DB="$APP_DIR/outgoing-webhook/database/events.db"

if [[ ! -f "$DB" ]]; then
  echo "❌ DB file not found: $DB"
  exit 1
fi

if ! command -v sqlite3 >/dev/null 2>&1; then
  echo "❌ sqlite3 is not installed"
  exit 1
fi

echo "== Wiping data in DB (structure will stay intact) =="
echo "DB: $DB"

# Сформировать DELETE для всех пользовательских таблиц
DELETE_SQL="$(sqlite3 "$DB" "SELECT group_concat('DELETE FROM \"' || replace(name,'\"','\"\"') || '\";', ' ')
FROM sqlite_master
WHERE type='table' AND name NOT LIKE 'sqlite_%';")"

if [[ -z "${DELETE_SQL// }" ]]; then
  echo "⚠️ No user tables found, nothing to delete"
else
  sqlite3 "$DB" "PRAGMA busy_timeout=5000; PRAGMA foreign_keys=OFF; BEGIN IMMEDIATE; ${DELETE_SQL} COMMIT;"
fi

# Сброс autoincrement-счётчиков (если таблица есть)
sqlite3 "$DB" "DELETE FROM sqlite_sequence;" || true

# Компактизация файла БД
sqlite3 "$DB" "PRAGMA foreign_keys=ON; PRAGMA wal_checkpoint(FULL); VACUUM; ANALYZE;"

echo "✅ Done"
echo "== DB size after cleanup =="
ls -lh "$DB"

echo "== Optional check =="
php "$APP_DIR/outgoing-webhook/tools/check-events-in-db.php" || true