# Обработчики очереди: process-queue.php и process-queue-cli.php

**Дата создания:** 2026-01-26 (UTC+3, Брест)  
**Версия:** 1.0

## Назначение

Документ описывает обработчики очереди событий: HTTP-обработчик (`process-queue.php`) и CLI-обработчик (`process-queue-cli.php`), их параметры, формат ответа и использование в cron.

---

## process-queue.php

**Файл:** `outgoing-webhook/tools/process-queue.php`

### Назначение

HTTP-обработчик очереди. Обрабатывает задания из очереди через HTTP-запрос.

### Доступ

**URL:** `https://your-domain.com/outgoing-webhook/tools/process-queue.php`

**Метод:** `GET` или `POST`

### Параметры

#### limit

**Тип:** `int` (query parameter)  
**По умолчанию:** `50`  
**Описание:** Максимальное количество заданий для обработки за один запуск

**Пример:**
```
https://your-domain.com/outgoing-webhook/tools/process-queue.php?limit=100
```

**Валидация:**
- Если `limit <= 0` — устанавливается `50`

### Алгоритм работы

1. **Валидация параметра limit:**
   ```php
   $limit = (int) ($_GET['limit'] ?? 50);
   if ($limit <= 0) {
       $limit = 50;
   }
   ```

2. **Получение сервисов:**
   ```php
   $services = outgoingWebhookGetServices();
   ```

3. **Запуск обработки:**
   ```php
   $result = $services['runner']->run($limit);
   ```

4. **Возврат результата:**
   ```php
   outgoingWebhookJsonResponse(200, $result);
   ```

### Формат ответа

```json
{
  "processed": 10,
  "processingMs": 5000,
  "queue": {
    "pending": 5,
    "processing": 0,
    "done": 100,
    "failed": 2
  }
}
```

### Описание полей

| Поле | Тип | Описание |
|------|-----|----------|
| `processed` | int | Количество обработанных заданий |
| `processingMs` | int | Время обработки в миллисекундах |
| `queue.pending` | int | Количество заданий в `pending/` |
| `queue.processing` | int | Количество заданий в `processing/` |
| `queue.done` | int | Количество заданий в `done/` |
| `queue.failed` | int | Количество заданий в `failed/` |

### Использование

**Через curl:**
```bash
curl "https://your-domain.com/outgoing-webhook/tools/process-queue.php?limit=100"
```

**Через wget:**
```bash
wget -qO- "https://your-domain.com/outgoing-webhook/tools/process-queue.php?limit=100"
```

**В cron:**
```cron
*/5 * * * * curl -s "https://your-domain.com/outgoing-webhook/tools/process-queue.php?limit=50" > /dev/null
```

---

## process-queue-cli.php

**Файл:** `outgoing-webhook/tools/process-queue-cli.php`

### Назначение

CLI-обработчик очереди. Обрабатывает задания из очереди через командную строку (для использования в cron).

### Доступ

**Через командную строку:**
```bash
php outgoing-webhook/tools/process-queue-cli.php
```

### Параметры

#### --limit

**Тип:** `int`  
**По умолчанию:** `50`  
**Описание:** Максимальное количество заданий для обработки

**Пример:**
```bash
php outgoing-webhook/tools/process-queue-cli.php --limit=100
```

**Валидация:**
- Если `limit <= 0` — устанавливается `50`

### Алгоритм работы

1. **Парсинг параметров:**
   ```php
   $options = getopt('', ['limit::']);
   $limit = isset($options['limit']) ? (int) $options['limit'] : 50;
   if ($limit <= 0) {
       $limit = 50;
   }
   ```

2. **Установка параметра для process-queue.php:**
   ```php
   $_GET['limit'] = $limit;
   ```

3. **Подключение process-queue.php:**
   ```php
   require __DIR__ . '/process-queue.php';
   ```

**Примечание:** CLI-обработчик является обёрткой над HTTP-обработчиком. Вся логика находится в `process-queue.php`.

### Формат вывода

**Вывод:** JSON в stdout (через `outgoingWebhookJsonResponse()`)

**Пример:**
```json
{
  "processed": 10,
  "processingMs": 5000,
  "queue": {
    "pending": 5,
    "processing": 0,
    "done": 100,
    "failed": 2
  }
}
```

### Использование

**Базовое использование:**
```bash
php outgoing-webhook/tools/process-queue-cli.php
```

**С параметром limit:**
```bash
php outgoing-webhook/tools/process-queue-cli.php --limit=100
```

**В cron:**
```cron
# Обработка очереди каждые 5 минут
*/5 * * * * cd /var/www/progect3.antonov-mark.ru && php outgoing-webhook/tools/process-queue-cli.php --limit=50 >> /var/log/queue-processor.log 2>&1
```

**С перенаправлением вывода:**
```bash
php outgoing-webhook/tools/process-queue-cli.php --limit=50 > /tmp/queue-result.json 2>&1
```

---

## Сравнение обработчиков

| Характеристика | process-queue.php | process-queue-cli.php |
|----------------|-------------------|----------------------|
| **Тип доступа** | HTTP | CLI |
| **Параметры** | Query string (`?limit=50`) | CLI опции (`--limit=50`) |
| **Использование** | curl, wget, cron через HTTP | Прямой вызов PHP, cron |
| **Зависимости** | Веб-сервер | PHP CLI |
| **Логика** | Основная логика | Обёртка над HTTP-обработчиком |

---

## Рекомендации по настройке cron

### Частота запуска

**Рекомендуется:** каждые 1-5 минут

**Пример (каждые 5 минут):**
```cron
*/5 * * * * cd /var/www/progect3.antonov-mark.ru && php outgoing-webhook/tools/process-queue-cli.php --limit=50 >> /var/log/queue-processor.log 2>&1
```

**Пример (каждую минуту):**
```cron
* * * * * cd /var/www/progect3.antonov-mark.ru && php outgoing-webhook/tools/process-queue-cli.php --limit=20 >> /var/log/queue-processor.log 2>&1
```

### Лимит заданий

**Рекомендации:**
- **Низкая нагрузка:** 20-50 заданий
- **Средняя нагрузка:** 50-100 заданий
- **Высокая нагрузка:** 100-200 заданий

**Зависит от:**
- Времени обработки одного задания
- Таймаута PHP
- Производительности сервера

### Логирование

**Рекомендуется:**
- Перенаправлять вывод в лог-файл
- Перенаправлять ошибки в тот же файл (`2>&1`)

**Пример:**
```cron
*/5 * * * * cd /var/www/progect3.antonov-mark.ru && php outgoing-webhook/tools/process-queue-cli.php --limit=50 >> /var/log/queue-processor.log 2>&1
```

### Мониторинг

**Проверка работы:**
```bash
# Проверка последних записей в логе
tail -f /var/log/queue-processor.log

# Проверка количества заданий в очереди
ls -1 outgoing-webhook/queue/pending/ | wc -l
```

---

## Обработка ошибок

### Ошибки выполнения

**Логирование:**
- Ошибки логируются через `ErrorService`
- Файл: `logs/errors/error-YYYYMMDD.log`

**Поведение:**
- Ошибки не прерывают обработку других заданий
- Задания с ошибками перемещаются в `failed/`

### Таймауты

**Ограничения:**
- Таймаут PHP (обычно 30-60 секунд)
- Таймаут веб-сервера (если используется HTTP-обработчик)

**Рекомендации:**
- Увеличить таймаут PHP для CLI: `set_time_limit(0)`
- Использовать CLI-обработчик для больших объёмов

---

## Производительность

### Типичное время обработки

- **Одно задание:** 200-500 мс (зависит от типа события)
- **50 заданий:** 10-25 секунд
- **100 заданий:** 20-50 секунд

### Факторы, влияющие на производительность

1. **Тип событий:**
   - События с загрузкой справочников обрабатываются дольше
   - События задач/комментариев требуют дополнительных REST-запросов

2. **Сетевая задержка:**
   - Зависит от расположения Bitrix24
   - Влияет на время REST-запросов

3. **Размер данных:**
   - Большие сущности обрабатываются дольше
   - Влияет на время записи файлов

---

## Мониторинг очереди

### Подсчёт заданий

```bash
# Pending
ls -1 outgoing-webhook/queue/pending/ | wc -l

# Processing
ls -1 outgoing-webhook/queue/processing/ | wc -l

# Done
ls -1 outgoing-webhook/queue/done/ | wc -l

# Failed
ls -1 outgoing-webhook/queue/failed/ | wc -l
```

### Анализ логов шагов

```bash
# Последние шаги обработки
tail -f outgoing-webhook/logs/queue-steps.log
```

### Проверка производительности

```bash
# Анализ времени обработки из ответа
curl -s "https://your-domain.com/outgoing-webhook/tools/process-queue.php?limit=50" | jq '.processingMs'
```

---

## Связанные документы

- `08-queue-services.md` — сервисы очереди (QueueRunner, QueueService)
- `10-queue-detailed.md` — детальное описание очереди
- `18-error-handling.md` — обработка ошибок

---

## История изменений

- 2026-01-26 (UTC+3, Брест): Создан документ с описанием обработчиков очереди.
