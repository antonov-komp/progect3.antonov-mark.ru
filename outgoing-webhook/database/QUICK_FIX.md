# Быстрое решение: Установка SQLite для PHP 8.3

**Проблема:** `could not find driver` при инициализации БД

**Причина:** Расширение `pdo_sqlite` не установлено

## Решение (1 команда)

```bash
sudo apt-get update && sudo apt-get install -y php8.3-sqlite3 && sudo systemctl restart php8.3-fpm
```

## Проверка

```bash
php -m | grep sqlite
```

Должно вывести: `pdo_sqlite`

## После установки

```bash
php outgoing-webhook/tools/init-database.php
```

## Если команда не работает

1. Проверьте версию PHP:
   ```bash
   php -v
   ```

2. Установите пакет для вашей версии PHP:
   ```bash
   # Для PHP 8.1
   sudo apt-get install php8.1-sqlite3
   
   # Для PHP 8.2
   sudo apt-get install php8.2-sqlite3
   
   # Для PHP 8.4
   sudo apt-get install php8.4-sqlite3
   ```

3. Перезапустите PHP-FPM или Apache:
   ```bash
   # PHP-FPM
   sudo systemctl restart php8.3-fpm
   
   # Apache
   sudo systemctl restart apache2
   ```
