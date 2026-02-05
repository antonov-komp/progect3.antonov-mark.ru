# TASK-032: Остановка таймера выполнения задачи в Bitrix24

**Дата создания:** 2026-02-05 19:00 (UTC+3, Брест)  
**Статус:** Документация  
**Исполнитель:** Bitrix24 Программист

## Описание

Документация по остановке таймера выполнения задачи в Bitrix24 через REST API, когда время было запущено принудительно.

## Контекст

В Bitrix24 есть встроенный таймер для отслеживания времени работы над задачами. Когда таймер запущен:
- Статус задачи меняется на "Выполняется"
- Создается активная запись трудозатрат (elapseditem) без даты окончания
- Время записывается в Трудозатраты по задаче

**Проблема:** Если время запущено принудительно, нужно знать, как его остановить программно через REST API.

## Методы Bitrix24 REST API

### 1. Получение списка трудозатрат задачи

**Метод:** `tasks.task.elapseditem.get`

**Документация:** https://context7.com/bitrix24/rest/tasks.task.elapseditem.get/

**Параметры:**
```php
[
    'TASKID' => 123,  // ID задачи
]
```

**Ответ:**
```php
[
    'result' => [
        [
            'ID' => '456',
            'TASK_ID' => '123',
            'USER_ID' => '1619',
            'MINUTES' => '30',  // Затраченное время в минутах
            'SECONDS' => '1800', // Затраченное время в секундах
            'CREATED_DATE' => '2026-02-05T17:00:00+03:00',
            'DATE_START' => '2026-02-05T17:00:00+03:00',
            'DATE_STOP' => null,  // null = таймер активен (не остановлен)
            'COMMENT_TEXT' => 'Работа над задачей',
        ],
        // ... другие записи
    ]
]
```

### 2. Обновление записи трудозатрат (остановка таймера)

**Метод:** `tasks.task.elapseditem.update`

**Документация:** https://context7.com/bitrix24/rest/tasks.task.elapseditem.update/

**Параметры для остановки таймера:**
```php
[
    'TASKID' => 123,      // ID задачи
    'ITEMID' => 456,      // ID записи трудозатрат (из elapseditem.get)
    'FIELDS' => [
        'DATE_STOP' => date('Y-m-d H:i:s'),  // Текущая дата/время для остановки
        // ИЛИ
        'MINUTES' => 45,  // Общее время в минутах (если нужно указать точное время)
        'SECONDS' => 2700, // Общее время в секундах
    ]
]
```

**Важно:** 
- Если указать `DATE_STOP`, таймер остановится на текущий момент
- Если указать `MINUTES` или `SECONDS`, время будет зафиксировано с указанным значением

### 3. Альтернативный способ: получение активной записи и обновление

**Алгоритм:**
1. Получить список всех трудозатрат задачи через `tasks.task.elapseditem.get`
2. Найти запись с `DATE_STOP === null` (активный таймер)
3. Обновить эту запись, указав `DATE_STOP` или `MINUTES`/`SECONDS`

## Пример реализации

### PHP пример (используя Bitrix24Client)

```php
<?php

require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

/**
 * Остановка таймера выполнения задачи
 * 
 * @param int $taskId ID задачи
 * @param Bitrix24Client $client Клиент Bitrix24 REST API
 * @return array Результат операции
 */
function stopTaskTimer(int $taskId, Bitrix24Client $client): array
{
    // 1. Получаем список всех трудозатрат задачи
    $elapsedResult = $client->call('tasks.task.elapseditem.get', [
        'TASKID' => $taskId
    ]);
    
    if (!empty($elapsedResult['error'])) {
        return [
            'success' => false,
            'error' => $elapsedResult['error'],
            'error_information' => $elapsedResult['error_information']
        ];
    }
    
    $elapsedItems = $elapsedResult['result'] ?? [];
    
    // 2. Находим активную запись (DATE_STOP === null)
    $activeItem = null;
    foreach ($elapsedItems as $item) {
        if (empty($item['DATE_STOP']) || $item['DATE_STOP'] === null) {
            $activeItem = $item;
            break;
        }
    }
    
    if ($activeItem === null) {
        return [
            'success' => false,
            'error' => 'no_active_timer',
            'error_information' => 'Активный таймер не найден для задачи #' . $taskId
        ];
    }
    
    $itemId = $activeItem['ID'];
    
    // 3. Вычисляем время работы (если нужно)
    $dateStart = new DateTime($activeItem['DATE_START']);
    $dateStop = new DateTime(); // Текущее время
    $interval = $dateStart->diff($dateStop);
    $totalMinutes = ($interval->h * 60) + $interval->i;
    $totalSeconds = ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
    
    // 4. Обновляем запись, указывая дату окончания
    $updateResult = $client->call('tasks.task.elapseditem.update', [
        'TASKID' => $taskId,
        'ITEMID' => $itemId,
        'FIELDS' => [
            'DATE_STOP' => $dateStop->format('Y-m-d H:i:s'),
            // ИЛИ можно указать точное время:
            // 'MINUTES' => $totalMinutes,
            // 'SECONDS' => $totalSeconds,
        ]
    ]);
    
    if (!empty($updateResult['error'])) {
        return [
            'success' => false,
            'error' => $updateResult['error'],
            'error_information' => $updateResult['error_information']
        ];
    }
    
    return [
        'success' => true,
        'item_id' => $itemId,
        'minutes' => $totalMinutes,
        'seconds' => $totalSeconds,
        'date_start' => $activeItem['DATE_START'],
        'date_stop' => $dateStop->format('Y-m-d H:i:s')
    ];
}

// Пример использования:
$client = new Bitrix24Client();
$result = stopTaskTimer(123, $client);

if ($result['success']) {
    echo "Таймер остановлен. Затрачено времени: {$result['minutes']} минут\n";
} else {
    echo "Ошибка: {$result['error']} - {$result['error_information']}\n";
}
```

### Упрощенный вариант (если известен ID записи)

```php
/**
 * Остановка таймера по известному ID записи трудозатрат
 */
function stopTaskTimerById(int $taskId, int $itemId, Bitrix24Client $client): array
{
    $result = $client->call('tasks.task.elapseditem.update', [
        'TASKID' => $taskId,
        'ITEMID' => $itemId,
        'FIELDS' => [
            'DATE_STOP' => date('Y-m-d H:i:s')
        ]
    ]);
    
    return $result;
}
```

## Интеграция с модулем исходящих событий

Если нужно остановить таймер при определенных событиях, можно добавить обработчик в `TaskEventHandler`:

```php
// outgoing-webhook/services/Event/Handlers/TaskEventHandler.php

/**
 * Остановка таймера при изменении статуса задачи
 */
private function handleTaskStatusChange(int $taskId, string $newStatus): void
{
    // Если статус изменился на "Завершена" или другой финальный статус
    if (in_array($newStatus, ['5', '6', '7'])) { // Примеры финальных статусов
        $this->stopTaskTimer($taskId);
    }
}

/**
 * Остановка таймера задачи
 */
private function stopTaskTimer(int $taskId): void
{
    // Получаем активную запись трудозатрат
    $elapsedResult = $this->rest->call('tasks.task.elapseditem.get', [
        'TASKID' => $taskId
    ]);
    
    if (empty($elapsedResult['error']) && !empty($elapsedResult['result'])) {
        foreach ($elapsedResult['result'] as $item) {
            if (empty($item['DATE_STOP'])) {
                // Останавливаем таймер
                $this->rest->call('tasks.task.elapseditem.update', [
                    'TASKID' => $taskId,
                    'ITEMID' => $item['ID'],
                    'FIELDS' => [
                        'DATE_STOP' => date('Y-m-d H:i:s')
                    ]
                ]);
                break;
            }
        }
    }
}
```

## Важные замечания

### Проблема с правами доступа (ERROR_METHOD_NOT_FOUND)

Если при вызове методов `tasks.task.elapseditem.*` вы получаете ошибку `ERROR_METHOD_NOT_FOUND`, это может означать:

1. **Методы недоступны в REST API** - возможно, эти методы доступны только в коробочной версии Bitrix24 через D7 ORM
2. **Недостаточно прав доступа** - хотя у вас есть `task`, `tasks`, `tasks_extended`, но методы `elapseditem.*` могут требовать дополнительных прав

**Важно:** Методы `tasks.task.elapseditem.*` могут быть **недоступны в облачной версии Bitrix24 через REST API**. Это внутренние методы PHP API, которые не экспортированы в REST.

**Альтернативные решения:**

#### 1. Использование полей задачи через `tasks.task.get`

Вместо методов `elapseditem.*` можно получить информацию о трудозатратах через поля задачи:

```php
$result = $client->call('tasks.task.get', [
    'id' => 1481,
    'select' => ['*', 'timeSpentInLogs', 'elapsedTime', 'timeEstimate']
]);

$task = $result['result']['task'] ?? $result['result'];
$timeSpent = $task['timeSpentInLogs'] ?? null;  // Затраченное время
$elapsedTime = $task['elapsedTime'] ?? null;     // Общее прошедшее время
```

**Проверка доступных полей:**
```bash
php outgoing-webhook/tools/check-task-fields.php 1481
```

#### 2. Работа через статус задачи

Можно косвенно управлять таймером через изменение статуса задачи:

```php
// Изменение статуса на "Выполняется" может запустить таймер
$client->call('tasks.task.update', [
    'id' => 1481,
    'fields' => [
        'status' => 3  // Статус "Выполняется"
    ]
]);
```

**Ограничение:** Это не гарантирует запуск/остановку таймера, так как таймер управляется отдельно от статуса.

#### 3. Использование вебхуков для отслеживания изменений

Если таймер запускается вручную через UI, можно отслеживать изменения через исходящие вебхуки:

- `ONTASKUPDATE` - при изменении задачи (включая изменения трудозатрат)
- Проверять изменения в полях `timeSpentInLogs` или `elapsedTime`

#### 4. Проверка доступности методов

Проверьте, какие методы доступны для вашего приложения:

```bash
php outgoing-webhook/tools/check-scope.php
php outgoing-webhook/tools/check-task-fields.php 1481
```

**Проверка доступности методов:**
```bash
# Проверить scope приложения
php outgoing-webhook/tools/check-scope.php

# Проверить статус таймера (скрипт попробует разные варианты методов)
php outgoing-webhook/tools/task-timer-control.php status 1481
```

### Другие важные замечания

1. **Одновременно только один активный таймер:** В Bitrix24 можно вести учет времени только в одной задаче одновременно. При запуске таймера в другой задаче первая автоматически ставится на паузу.

2. **Права доступа:** Для остановки таймера нужны права на изменение задачи. Обычно это:
   - Создатель задачи
   - Ответственный за задачу
   - Соисполнитель (если он запустил таймер)

3. **Автоматическая остановка:** Таймер автоматически останавливается при:
   - Завершении задачи (статус "Завершена")
   - Запуске таймера в другой задаче
   - Выходе пользователя из системы (в некоторых случаях)

4. **Формат даты:** Используйте формат `Y-m-d H:i:s` для `DATE_STOP` (например, `2026-02-05 19:30:00`).

## Тестирование

### Проверка работы таймера

1. **Запустить таймер в задаче:**
   ```php
   $client->call('tasks.task.elapseditem.add', [
       'TASKID' => 123,
       'FIELDS' => [
           'COMMENT_TEXT' => 'Начало работы'
       ]
   ]);
   ```

2. **Проверить активную запись:**
   ```php
   $result = $client->call('tasks.task.elapseditem.get', [
       'TASKID' => 123
   ]);
   // Найти запись с DATE_STOP === null
   ```

3. **Остановить таймер:**
   ```php
   $result = stopTaskTimer(123, $client);
   ```

4. **Проверить, что таймер остановлен:**
   ```php
   $result = $client->call('tasks.task.elapseditem.get', [
       'TASKID' => 123
   ]);
   // Все записи должны иметь DATE_STOP !== null
   ```

## Ссылки на документацию

- `tasks.task.elapseditem.get`: https://context7.com/bitrix24/rest/tasks.task.elapseditem.get/
- `tasks.task.elapseditem.update`: https://context7.com/bitrix24/rest/tasks.task.elapseditem.update/
- `tasks.task.elapseditem.add`: https://context7.com/bitrix24/rest/tasks.task.elapseditem.add/
- `tasks.task.get`: https://context7.com/bitrix24/rest/tasks.task.get/

## Запуск таймера (если время еще не запущено)

### Метод: `tasks.task.elapseditem.add`

**Документация:** https://context7.com/bitrix24/rest/tasks.task.elapseditem.add/

**Важно:** Для запуска таймера нужно создать запись трудозатрат **без указания `DATE_STOP`**. Это создаст активную запись, которая будет работать как таймер.

**Параметры для запуска таймера:**
```php
[
    'TASKID' => 1481,  // ID задачи
    'FIELDS' => [
        'COMMENT_TEXT' => 'Начало работы',  // Опционально
        'DATE_START' => date('Y-m-d H:i:s'),  // Опционально, по умолчанию текущее время
        // НЕ указываем DATE_STOP - это создаст активный таймер
    ]
]
```

**Пример запуска таймера:**
```php
$result = $client->call('tasks.task.elapseditem.add', [
    'TASKID' => 1481,
    'FIELDS' => [
        'COMMENT_TEXT' => 'Таймер запущен через API',
        'DATE_START' => date('Y-m-d H:i:s')
    ]
]);

if (empty($result['error'])) {
    $itemId = $result['result'];
    echo "Таймер запущен. ID записи: $itemId\n";
} else {
    echo "Ошибка: {$result['error']}\n";
}
```

### Проверка перед запуском

Перед запуском таймера рекомендуется проверить:
1. Существует ли задача
2. Нет ли уже активного таймера
3. Есть ли права на запуск таймера

**Пример проверки:**
```php
// 1. Проверяем задачу
$taskResult = $client->call('tasks.task.get', ['id' => 1481]);
if (!empty($taskResult['error'])) {
    die("Задача не найдена или нет доступа\n");
}

// 2. Проверяем активный таймер
$elapsedResult = $client->call('tasks.task.elapseditem.get', [
    'TASKID' => 1481
]);

$hasActiveTimer = false;
if (!empty($elapsedResult['result'])) {
    foreach ($elapsedResult['result'] as $item) {
        if (empty($item['DATE_STOP'])) {
            $hasActiveTimer = true;
            break;
        }
    }
}

if ($hasActiveTimer) {
    echo "Таймер уже запущен\n";
} else {
    // Запускаем таймер
    $result = $client->call('tasks.task.elapseditem.add', [
        'TASKID' => 1481,
        'FIELDS' => [
            'COMMENT_TEXT' => 'Запуск таймера'
        ]
    ]);
}
```

## Утилита для управления таймером

Создан скрипт `outgoing-webhook/tools/task-timer-control.php` для удобного управления таймером из командной строки:

**Использование:**
```bash
# Запустить таймер для задачи 1481
php outgoing-webhook/tools/task-timer-control.php start 1481

# Остановить таймер
php outgoing-webhook/tools/task-timer-control.php stop 1481

# Проверить статус таймера
php outgoing-webhook/tools/task-timer-control.php status 1481
```

## Работа напрямую с задачей через API

### Можно ли использовать `tasks.task.update` для управления таймером?

**Короткий ответ:** Нет, напрямую через `tasks.task.update` нельзя запустить или остановить таймер.

**Почему:**
- `tasks.task.update` работает с полями задачи (title, status, priority и т.д.)
- Таймер — это отдельная сущность (elapseditem), которая связана с задачей, но не является её полем
- Для управления таймером нужно использовать методы `tasks.task.elapseditem.*`

### Что можно сделать через `tasks.task.update`:

```php
// Изменить статус задачи (может повлиять на таймер косвенно)
$client->call('tasks.task.update', [
    'id' => 1481,
    'fields' => [
        'status' => 3  // Например, статус "Выполняется"
    ]
]);

// Изменить другие поля задачи
$client->call('tasks.task.update', [
    'id' => 1481,
    'fields' => [
        'title' => 'Новое название',
        'priority' => 2
    ]
]);
```

**Важно:** Изменение статуса задачи на "Завершена" может автоматически остановить таймер, но это зависит от настроек Bitrix24 и не является надежным способом управления таймером.

### Рекомендуемый подход:

1. **Для запуска таймера:** Используйте `tasks.task.elapseditem.add` без `DATE_STOP`
2. **Для остановки таймера:** Используйте `tasks.task.elapseditem.update` с указанием `DATE_STOP`
3. **Для проверки статуса:** Используйте `tasks.task.elapseditem.get` и ищите запись с `DATE_STOP === null`

## Пример полного цикла работы с таймером

```php
<?php

require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

$client = new Bitrix24Client();
$taskId = 1481;

// 1. Проверяем статус задачи
$taskResult = $client->call('tasks.task.get', ['id' => $taskId]);
if (!empty($taskResult['error'])) {
    die("Ошибка получения задачи: {$taskResult['error']}\n");
}

$task = $taskResult['result']['task'] ?? $taskResult['result'];
echo "Задача: {$task['title']}\n";
echo "Статус: {$task['status']}\n\n";

// 2. Проверяем активный таймер
$elapsedResult = $client->call('tasks.task.elapseditem.get', [
    'TASKID' => $taskId
]);

$activeItem = null;
if (!empty($elapsedResult['result'])) {
    foreach ($elapsedResult['result'] as $item) {
        if (empty($item['DATE_STOP'])) {
            $activeItem = $item;
            break;
        }
    }
}

if ($activeItem) {
    echo "Таймер активен с: {$activeItem['DATE_START']}\n";
    
    // Останавливаем таймер
    $stopResult = $client->call('tasks.task.elapseditem.update', [
        'TASKID' => $taskId,
        'ITEMID' => $activeItem['ID'],
        'FIELDS' => [
            'DATE_STOP' => date('Y-m-d H:i:s')
        ]
    ]);
    
    if (empty($stopResult['error'])) {
        echo "Таймер остановлен\n";
    }
} else {
    echo "Таймер не запущен\n";
    
    // Запускаем таймер
    $startResult = $client->call('tasks.task.elapseditem.add', [
        'TASKID' => $taskId,
        'FIELDS' => [
            'COMMENT_TEXT' => 'Запуск таймера через API'
        ]
    ]);
    
    if (empty($startResult['error'])) {
        echo "Таймер запущен. ID записи: {$startResult['result']}\n";
    } else {
        echo "Ошибка запуска: {$startResult['error']}\n";
    }
}
```

## Реальные результаты проверки

### Проверка доступности методов (2026-02-05)

**Scope приложения:**
- ✅ `task`, `tasks`, `tasks_extended` - есть
- ❌ Методы `tasks.task.elapseditem.*` - **недоступны в REST API**

**Доступные поля задачи:**
- ✅ `timeSpentInLogs` - затраченное время (может быть `null` если таймер не запущен)
- ✅ `timeEstimate` - плановое время
- ✅ `allowTimeTracking` - разрешен ли учет времени (`"Y"` или `"N"`)

**Вывод:**
Методы `tasks.task.elapseditem.*` **не экспортированы в REST API Bitrix24**. Это внутренние методы PHP API (класс `CTaskElapsedItem`), которые доступны только в коробочной версии через D7 ORM.

### Рабочее решение

**Для отслеживания статуса таймера:**
```php
$result = $client->call('tasks.task.get', [
    'id' => 1481,
    'select' => ['*']
]);

$task = $result['result']['task'];
$timeSpentInLogs = $task['timeSpentInLogs'];  // null = таймер не запущен или не было записей
$allowTimeTracking = ($task['allowTimeTracking'] ?? 'N') === 'Y';
```

**Для управления таймером:**
- ❌ Прямое управление через REST API **невозможно**
- ✅ Используйте UI Bitrix24 для запуска/остановки таймера
- ✅ Отслеживайте изменения через вебхуки `ONTASKUPDATE` и анализируйте изменения в `timeSpentInLogs`

## Принудительный запуск таймера

### Проблема

Методы `tasks.task.elapseditem.*` недоступны в REST API, поэтому **прямой запуск таймера через API невозможен**.

### Доступные способы

#### 1. Изменение статуса задачи на "Выполняется"

Это может косвенно повлиять на таймер, но не гарантирует его запуск:

```php
$client->call('tasks.task.update', [
    'id' => 1481,
    'fields' => [
        'status' => 3  // Статус "Выполняется"
    ]
]);
```

**Ограничение:** Это не гарантирует запуск таймера. Таймер управляется отдельно от статуса задачи.

#### 2. Использование скрипта для попытки запуска

Создан скрипт `outgoing-webhook/tools/force-start-timer.php`, который пробует все доступные методы:

```bash
php outgoing-webhook/tools/force-start-timer.php 1481
```

Скрипт:
- Проверяет текущее состояние задачи
- Пробует изменить статус на "Выполняется"
- Пробует все варианты методов для добавления трудозатрат
- Проверяет результат через несколько секунд

#### 3. Ручной запуск через UI Bitrix24

**Самый надежный способ:**
1. Откройте задачу в Bitrix24
2. Нажмите кнопку "Начать" в интерфейсе задачи
3. Таймер запустится автоматически

#### 4. Отслеживание через вебхуки

Если таймер запускается вручную, можно отслеживать изменения через вебхуки:

```php
// В обработчике вебхука ONTASKUPDATE
$task = $payload['data']['FIELDS'];
$timeSpentInLogs = $task['TIME_SPENT_IN_LOGS'] ?? null;

if ($timeSpentInLogs !== null && $timeSpentInLogs > 0) {
    // Таймер запущен или время учитывается
    // Реагируйте на это событие
}
```

### Практический пример

```php
<?php

require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

$client = new Bitrix24Client();
$taskId = 1481;

// 1. Проверяем текущее состояние
$task = $client->call('tasks.task.get', ['id' => $taskId])['result']['task'];
$allowTimeTracking = ($task['allowTimeTracking'] ?? 'N') === 'Y';

if (!$allowTimeTracking) {
    die("Учет времени отключен для задачи\n");
}

// 2. Меняем статус на "Выполняется"
$result = $client->call('tasks.task.update', [
    'id' => $taskId,
    'fields' => ['status' => 3]
]);

if (empty($result['error'])) {
    echo "Статус изменен на 'Выполняется'\n";
    echo "⚠️  ВНИМАНИЕ: Таймер нужно запустить вручную через UI Bitrix24!\n";
    echo "Или отслеживайте изменения через вебхуки ONTASKUPDATE\n";
} else {
    echo "Ошибка: {$result['error']}\n";
}
```

### Итоговые рекомендации

**Для принудительного запуска таймера:**

1. ✅ **Используйте UI Bitrix24** - самый надежный способ
2. ✅ **Измените статус на "Выполняется"** - может помочь косвенно
3. ✅ **Отслеживайте через вебхуки** - реагируйте на изменения `timeSpentInLogs`
4. ❌ **Прямой запуск через REST API** - невозможен (методы недоступны)

**Для автоматизации:**
- Настройте вебхук `ONTASKUPDATE`
- Отслеживайте изменения в поле `timeSpentInLogs`
- Реагируйте на изменения в реальном времени
- Используйте бизнес-процессы Bitrix24 для автоматизации

## История изменений

- 2026-02-05 19:00 (UTC+3, Брест): Создана документация по остановке таймера задачи
- 2026-02-05 19:30 (UTC+3, Брест): Добавлена информация о запуске таймера и работе напрямую с задачей через API
- 2026-02-05 20:00 (UTC+3, Брест): Обновлена документация с реальными результатами проверки - методы `tasks.task.elapseditem.*` недоступны в REST API
- 2026-02-05 20:30 (UTC+3, Брест): Добавлен раздел о принудительном запуске таймера с практическими примерами и альтернативными решениями
