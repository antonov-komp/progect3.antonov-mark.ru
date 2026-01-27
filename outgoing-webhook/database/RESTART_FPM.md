# Перезапуск PHP-FPM для применения SQLite

**Проблема:** Веб-сервер не видит расширение `pdo_sqlite`, хотя оно установлено.

**Причина:** PHP-FPM нужно перезапустить после установки расширения.

## Решение

### 1. Перезапуск PHP-FPM

```bash
# Определить версию PHP-FPM
systemctl list-units | grep php.*fpm

# Перезапустить PHP-FPM (замените версию на вашу)
sudo systemctl restart php8.3-fpm

# Или для всех версий PHP-FPM
sudo systemctl restart php*-fpm.service
```

### 2. Проверка после перезапуска

Создайте тестовый PHP файл для проверки через веб-сервер:

```php
<?php
// test-sqlite.php
if (extension_loaded('pdo_sqlite')) {
    echo "✓ PDO_SQLITE загружен\n";
    $drivers = PDO::getAvailableDrivers();
    echo "Драйверы: " . implode(', ', $drivers) . "\n";
} else {
    echo "✗ PDO_SQLITE НЕ загружен\n";
}
```

Откройте через браузер: `http://ваш-домен/test-sqlite.php`

### 3. Альтернативный способ перезапуска

Если `systemctl` недоступен:

```bash
# Найти процесс PHP-FPM
ps aux | grep php-fpm

# Перезапустить через kill (мягкий перезапуск)
sudo kill -USR2 $(pgrep -f php-fpm)

# Или через service
sudo service php8.3-fpm restart
```

## После перезапуска

1. Отправьте тестовое событие через webhook
2. Проверьте БД:
   ```bash
   php outgoing-webhook/tools/check-database.php
   ```
3. Проверьте логи на наличие ошибок БД

## Проверка через веб-сервер

Если у вас есть доступ к веб-серверу, создайте файл `test-sqlite-web.php`:

```php
<?php
header('Content-Type: text/plain');
echo "Проверка SQLite для веб-сервера\n";
echo "================================\n\n";

if (extension_loaded('pdo_sqlite')) {
    echo "✓ PDO_SQLITE загружен\n";
    $drivers = PDO::getAvailableDrivers();
    echo "Доступные драйверы: " . implode(', ', $drivers) . "\n";
    
    if (in_array('sqlite', $drivers)) {
        echo "✓ SQLite драйвер доступен\n";
    } else {
        echo "✗ SQLite драйвер недоступен\n";
    }
} else {
    echo "✗ PDO_SQLITE НЕ загружен\n";
    echo "Нужно перезапустить PHP-FPM\n";
}
```

Откройте через браузер и проверьте вывод.
