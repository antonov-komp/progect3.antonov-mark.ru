# Исправление прав доступа к БД

**Проблема:** Файл БД принадлежит root, а веб-сервер работает от www-data

**Решение:**

```bash
# Изменить владельца файла БД
sudo chown www-data:www-data /var/www/progect3.antonov-mark.ru/outgoing-webhook/database/events.db

# Установить права на запись
sudo chmod 664 /var/www/progect3.antonov-mark.ru/outgoing-webhook/database/events.db

# Также для директории database
sudo chown www-data:www-data /var/www/progect3.antonov-mark.ru/outgoing-webhook/database/
sudo chmod 755 /var/www/progect3.antonov-mark.ru/outgoing-webhook/database/
```

**Проверка:**

```bash
ls -la /var/www/progect3.antonov-mark.ru/outgoing-webhook/database/events.db
```

Должно показать: `-rw-rw-r-- 1 www-data www-data ...`
