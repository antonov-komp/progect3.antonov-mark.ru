# Установка SQLite для PHP

Дата: 2026-01-27 (UTC+3, Брест)

## Проблема

При попытке инициализации БД возникает ошибка:
```
could not find driver
```

Это означает, что расширение PDO_SQLITE не установлено в PHP.

## Решение

### Для Debian/Ubuntu

1. Установите расширение SQLite для PHP:

```bash
# Для PHP 8.x
sudo apt-get update
sudo apt-get install php8.x-sqlite3

# Или для конкретной версии (замените x на версию)
sudo apt-get install php8.1-sqlite3
# или
sudo apt-get install php8.2-sqlite3
# или
sudo apt-get install php8.4-sqlite3
```

2. Перезапустите веб-сервер:

```bash
# Для Apache
sudo systemctl restart apache2

# Для Nginx + PHP-FPM
sudo systemctl restart php8.x-fpm
sudo systemctl restart nginx
```

3. Проверьте установку:

```bash
php -m | grep -i sqlite
```

Должно вывести: `pdo_sqlite`

### Для CentOS/RHEL

```bash
sudo yum install php-pdo php-sqlite3
# или для PHP 8.x
sudo dnf install php-pdo php-sqlite3

sudo systemctl restart httpd
# или
sudo systemctl restart php-fpm
```

### Проверка через PHP

```bash
php -r "var_dump(extension_loaded('pdo_sqlite'));"
```

Должно вывести: `bool(true)`

## После установки

Запустите инициализацию БД:

```bash
php outgoing-webhook/tools/init-database.php
```

## Альтернатива: Компиляция из исходников

Если пакет недоступен, можно скомпилировать расширение из исходников, но это более сложный процесс и обычно не требуется.
