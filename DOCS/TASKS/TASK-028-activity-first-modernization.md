# TASK-028: Модернизация ActivityFirst — поддержка нескольких типов Activity

**Дата создания:** 2026-01-27 (UTC+3, Брест)  
**Статус:** draft  
**Приоритет:** high  
**Исполнитель:** Bitrix24 Программист (PHP)

## Описание

Модернизация системы ActivityFirst для поддержки нескольких типов Activity с разными условиями и полями сделок. Реализация модульной архитектуры для будущего расширения.

## Контекст

**Текущая ситуация:**
- ActivityFirst работает только с одним типом условий ("обложка")
- Используется одно поле сделки `UF_CRM_1759233362672`
- Архитектура не позволяет легко добавлять новые типы Activity
- Ключевые слова хранятся в одном массиве без учета опечаток

**Требования:**
1. Заменить поле для "обложка" с `UF_CRM_1759233362672` на `UF_CRM_1769520892`
2. Добавить новый тип Activity "Согласованный бланк" с обработкой опечаток (одна "н", разные регистры)
3. Для "Согласованный бланк" использовать старое поле `UF_CRM_1759233362672`
4. Создать расширяемую архитектуру для будущих типов Activity

**Проблема:**
- Текущая архитектура не масштабируется для нескольких типов Activity
- Жестко закодированное поле сделки в коде
- Нет механизма обработки опечаток в ключевых словах

## Модули и компоненты

### Файлы для изменения:

1. **`outgoing-webhook/activity/first/conditions.php`** (рефакторинг)
   - Переименовать в `outgoing-webhook/activity/config.php`
   - Изменить структуру на поддержку нескольких типов Activity
   - Добавить конфигурацию для каждого типа (ключевые слова, поле сделки)

2. **`outgoing-webhook/services/Task/TaskDetailsService.php`**
   - Рефакторинг методов `loadActivityFirstConditions()` → `loadActivityConfig()`
   - Рефакторинг `evaluateActivityFirst()` → `evaluateActivity(array $details): ?string`
   - Добавить метод `getActivityDealField(string $activityType): string`
   - Добавить метод `normalizeKeyword(string $keyword): string` для обработки опечаток

3. **`outgoing-webhook/services/Task/Comment/ActivityFirstProcessor.php`**
   - Переименовать в `ActivityProcessor.php`
   - Изменить метод `process()` для работы с типом Activity
   - Использовать динамическое поле сделки из конфигурации

4. **`outgoing-webhook/services/Task/Comment/CommentBuilder.php`**
   - Изменить установку `activityFirst` → `activityType` (строка или null)
   - Использовать новый метод `evaluateActivity()`

5. **`outgoing-webhook/bootstrap.php`**
   - Обновить функции для работы с новой архитектурой
   - Обновить `outgoingWebhookProcessActivityFirstSync()` → `outgoingWebhookProcessActivitySync()`

6. **`outgoing-webhook/index.php`**
   - Обновить проверки для работы с `activityType` вместо `activityFirst`

### Новые файлы:

1. **`outgoing-webhook/activity/config.php`** (новая структура конфигурации)
   - Конфигурация всех типов Activity
   - Определение ключевых слов, полей сделок, обработка опечаток

2. **`outgoing-webhook/services/Task/Activity/ActivityConfigService.php`** (новый сервис)
   - Загрузка и валидация конфигурации Activity
   - Нормализация ключевых слов (обработка опечаток)
   - Получение поля сделки для типа Activity

## Зависимости

- Использует существующую логику из `ActivityFirstProcessor`
- Использует `TaskDetailsService` для оценки условий
- Использует `DealFileService` для обновления файлов сделок
- Использует `TaskFilesService` для прикрепления файлов к задачам
- Зависит от структуры `commentDetails` с полями: `projectId`, `crmLinks`, `message`, `fileIds`

## Ступенчатые подзадачи

### Этап 1: Рефакторинг конфигурации Activity

1. **Создать новую структуру конфигурации**
   - Создать файл `outgoing-webhook/activity/config.php`
   - Определить структуру для нескольких типов Activity
   - Мигрировать текущие условия "обложка" в новую структуру

2. **Создать ActivityConfigService**
   - Создать класс `ActivityConfigService`
   - Метод `loadConfig(): array` — загрузка конфигурации
   - Метод `getActivityTypes(): array` — получение всех типов Activity
   - Метод `getActivityConfig(string $type): ?array` — получение конфигурации типа
   - Метод `getDealField(string $type): string` — получение поля сделки для типа

3. **Добавить обработку опечаток**
   - Метод `normalizeKeyword(string $keyword): string` — нормализация ключевых слов
   - Поддержка опечаток "Согласованный бланк" / "Согласованый бланк" (одна "н")
   - Регистронезависимый поиск

### Этап 2: Рефакторинг TaskDetailsService

4. **Обновить методы оценки Activity**
   - Рефакторинг `loadActivityFirstConditions()` → `loadActivityConfig()`
   - Рефакторинг `evaluateActivityFirst()` → `evaluateActivity(array $details): ?string`
   - Возвращает тип Activity (строка) или `null` если условия не выполнены
   - Использовать `ActivityConfigService` для получения конфигурации

5. **Добавить методы для работы с типами Activity**
   - Метод `getActivityDealField(string $activityType): string` — получение поля сделки
   - Метод `messageHasActivityKeyword(string $message, string $activityType): bool` — проверка ключевых слов с учетом опечаток

### Этап 3: Рефакторинг ActivityProcessor

6. **Переименовать и модернизировать ActivityFirstProcessor**
   - Переименовать класс в `ActivityProcessor`
   - Изменить метод `process()` для работы с типом Activity
   - Использовать динамическое поле сделки из конфигурации

7. **Обновить логику обработки**
   - Принимать `activityType` вместо проверки `activityFirst`
   - Получать поле сделки через `TaskDetailsService::getActivityDealField()`
   - Обновлять правильное поле сделки в зависимости от типа Activity

### Этап 4: Обновление CommentBuilder и обработчиков

8. **Обновить CommentBuilder**
   - Изменить установку `activityFirst` → `activityType`
   - Использовать `evaluateActivity()` вместо `evaluateActivityFirst()`
   - Сохранять тип Activity в `commentDetails['activityType']`

9. **Обновить CommentEventHandler**
   - Изменить проверки с `activityFirst` на `activityType`
   - Обновить логику синхронной обработки

10. **Обновить CommentDetailsService**
    - Изменить проверки с `activityFirst` на `activityType`
    - Передавать `activityType` в `ActivityProcessor::process()`

### Этап 5: Обновление bootstrap и index.php

11. **Обновить функции в bootstrap.php**
    - Рефакторинг `outgoingWebhookProcessActivityFirstSync()` → `outgoingWebhookProcessActivitySync()`
    - Обновить проверки для работы с `activityType`
    - Обновить логирование (добавить поле `activityType`)

12. **Обновить index.php**
    - Изменить проверки с `activityFirst` на `activityType`
    - Обновить вызовы функций синхронной обработки

### Этап 6: Обновление логирования и метрик

13. **Обновить логирование Activity**
    - Добавить поле `activityType` в логи `activity-first.log`
    - Обновить метрики в `activity-first-metrics.log`
    - Сохранить обратную совместимость (если возможно)

14. **Обновить маркировку обработанных событий**
    - Добавить `activityType` в файлы состояния `activity-first-processed/`
    - Обновить проверку `isActivityFirstProcessed()` для учета типа Activity

## Технические требования

### Структура новой конфигурации

**Файл:** `outgoing-webhook/activity/config.php`

```php
<?php
declare(strict_types=1);

return [
    // Общие настройки для всех Activity
    'common' => [
        'projectId' => '15',
        'crmDealPrefix' => 'D_',
    ],
    
    // Конфигурация типов Activity
    'activities' => [
        'cover' => [
            'name' => 'Обложка',
            'keywords' => [
                'обложка',
                'Обложка',
                'ОБЛОЖКА',
            ],
            'dealField' => 'UF_CRM_1769520892', // Новое поле
            'normalizeKeywords' => true, // Обработка опечаток
        ],
        'approved_form' => [
            'name' => 'Согласованный бланк',
            'keywords' => [
                'согласованный бланк',
                'Согласованный бланк',
                'СОГЛАСОВАННЫЙ БЛАНК',
                'согласованый бланк', // Опечатка: одна "н"
                'Согласованый бланк',
                'СОГЛАСОВАНЫЙ БЛАНК',
            ],
            'dealField' => 'UF_CRM_1759233362672', // Старое поле
            'normalizeKeywords' => true, // Обработка опечаток
        ],
    ],
];
```

### Обработка опечаток

**Метод:** `ActivityConfigService::normalizeKeyword(string $keyword): string`

**Логика:**
- Нормализация "согласованный" / "согласованый" → "согласованный" (две "н")
- Регистронезависимый поиск
- Удаление лишних пробелов

**Пример:**
```php
normalizeKeyword('Согласованый бланк') → 'согласованный бланк'
normalizeKeyword('СОГЛАСОВАНЫЙ БЛАНК') → 'согласованный бланк'
normalizeKeyword('обложка') → 'обложка'
```

### Оценка Activity

**Метод:** `TaskDetailsService::evaluateActivity(array $details): ?string`

**Алгоритм:**
1. Загрузить конфигурацию через `ActivityConfigService`
2. Для каждого типа Activity:
   - Проверить проект (если задан)
   - Проверить CRM-связь со сделкой
   - Проверить наличие файлов
   - Проверить ключевые слова в сообщении (с учетом опечаток)
3. Вернуть первый подходящий тип Activity или `null`

**Возвращает:**
- `'cover'` — если выполнены условия для "Обложка"
- `'approved_form'` — если выполнены условия для "Согласованный бланк"
- `null` — если условия не выполнены

### Обработка Activity

**Метод:** `ActivityProcessor::process(array $commentDetails, string $activityType, string $entityId, callable $restCall): array`

**Изменения:**
- Принимает `activityType` вместо проверки `activityFirst`
- Получает поле сделки через `TaskDetailsService::getActivityDealField($activityType)`
- Использует правильное поле сделки при обновлении

**Пример:**
```php
$activityType = 'cover'; // или 'approved_form'
$dealField = $this->taskDetails->getActivityDealField($activityType);
// Для 'cover' → 'UF_CRM_1769520892'
// Для 'approved_form' → 'UF_CRM_1759233362672'

$this->dealFiles->updateDealFiles($dealId, $dealField, $fileDataList, $restCall);
```

### Обратная совместимость

**Поддержка старого формата:**
- В `commentDetails` может быть как `activityFirst` (bool), так и `activityType` (string)
- При наличии `activityType` использовать его
- При наличии только `activityFirst` определить тип по конфигурации (если возможно)

**Миграция логов:**
- Старые логи с `activityFirst: true` остаются без изменений
- Новые логи содержат `activityType: 'cover'` или `activityType: 'approved_form'`

## API-методы Bitrix24

Используются те же методы REST API:
- `tasks.task.files.attach` — прикрепление файлов к задаче
- `disk.file.get` — получение информации о файле
- `crm.deal.update` — обновление файлового поля сделки (поле зависит от типа Activity)

Документация:
- https://context7.com/bitrix24/rest/tasks.task.files.attach
- https://context7.com/bitrix24/rest/disk.file.get
- https://context7.com/bitrix24/rest/crm.deal.update

## Критерии приёмки

- [ ] Создан файл `outgoing-webhook/activity/config.php` с новой структурой конфигурации
- [ ] Создан класс `ActivityConfigService` с методами загрузки и валидации конфигурации
- [ ] Реализована обработка опечаток для "Согласованный бланк" (одна "н")
- [ ] Метод `evaluateActivity()` возвращает тип Activity или `null`
- [ ] Метод `getActivityDealField()` возвращает правильное поле сделки для типа Activity
- [ ] `ActivityFirstProcessor` переименован в `ActivityProcessor` и модернизирован
- [ ] Для "обложка" используется поле `UF_CRM_1769520892`
- [ ] Для "Согласованный бланк" используется поле `UF_CRM_1759233362672`
- [ ] `CommentBuilder` устанавливает `activityType` вместо `activityFirst`
- [ ] Синхронная обработка работает с новыми типами Activity
- [ ] Логирование содержит поле `activityType`
- [ ] Обратная совместимость сохранена (если возможно)
- [ ] Архитектура позволяет легко добавлять новые типы Activity
- [ ] Тестирование показало, что оба типа Activity работают корректно
- [ ] Тестирование показало, что опечатки обрабатываются правильно

## Примеры кода

### Пример 1: Новая структура конфигурации

```php
<?php
// outgoing-webhook/activity/config.php

declare(strict_types=1);

return [
    'common' => [
        'projectId' => '15',
        'crmDealPrefix' => 'D_',
    ],
    
    'activities' => [
        'cover' => [
            'name' => 'Обложка',
            'keywords' => ['обложка', 'Обложка', 'ОБЛОЖКА'],
            'dealField' => 'UF_CRM_1769520892',
            'normalizeKeywords' => true,
        ],
        'approved_form' => [
            'name' => 'Согласованный бланк',
            'keywords' => [
                'согласованный бланк',
                'Согласованный бланк',
                'СОГЛАСОВАННЫЙ БЛАНК',
                'согласованый бланк', // Опечатка
                'Согласованый бланк',
                'СОГЛАСОВАНЫЙ БЛАНК',
            ],
            'dealField' => 'UF_CRM_1759233362672',
            'normalizeKeywords' => true,
        ],
    ],
];
```

### Пример 2: ActivityConfigService

```php
<?php
// outgoing-webhook/services/Task/Activity/ActivityConfigService.php

declare(strict_types=1);

class ActivityConfigService
{
    private array $config;
    
    public function __construct(string $configPath)
    {
        $this->config = $this->loadConfig($configPath);
    }
    
    public function loadConfig(string $path): array
    {
        if (!file_exists($path)) {
            return $this->getDefaultConfig();
        }
        
        $loaded = require $path;
        return is_array($loaded) ? $loaded : $this->getDefaultConfig();
    }
    
    public function getActivityTypes(): array
    {
        return array_keys($this->config['activities'] ?? []);
    }
    
    public function getActivityConfig(string $type): ?array
    {
        return $this->config['activities'][$type] ?? null;
    }
    
    public function getDealField(string $type): string
    {
        $activityConfig = $this->getActivityConfig($type);
        return $activityConfig['dealField'] ?? '';
    }
    
    public function normalizeKeyword(string $keyword): string
    {
        // Нормализация опечаток
        $normalized = mb_strtolower(trim($keyword));
        
        // Исправление "согласованый" → "согласованный" (две "н")
        $normalized = preg_replace('/согласованый/', 'согласованный', $normalized);
        
        return $normalized;
    }
    
    public function messageHasActivityKeyword(string $message, string $activityType): bool
    {
        $config = $this->getActivityConfig($activityType);
        if ($config === null) {
            return false;
        }
        
        $keywords = $config['keywords'] ?? [];
        $normalize = $config['normalizeKeywords'] ?? false;
        
        $messageNormalized = $normalize ? $this->normalizeKeyword($message) : mb_strtolower($message);
        
        foreach ($keywords as $keyword) {
            $keywordNormalized = $normalize ? $this->normalizeKeyword($keyword) : mb_strtolower($keyword);
            
            if (mb_stripos($messageNormalized, $keywordNormalized) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'common' => [
                'projectId' => null,
                'crmDealPrefix' => 'D_',
            ],
            'activities' => [],
        ];
    }
}
```

### Пример 3: Обновленный evaluateActivity

```php
<?php
// TaskDetailsService::evaluateActivity()

public function evaluateActivity(array $details): ?string
{
    $configService = new ActivityConfigService($this->basePath . '/activity/config.php');
    $commonConfig = $configService->loadConfig($this->basePath . '/activity/config.php')['common'] ?? [];
    
    $projectId = (string) ($commonConfig['projectId'] ?? '');
    $dealPrefix = (string) ($commonConfig['crmDealPrefix'] ?? 'D_');
    
    // Проверка проекта
    if ($projectId !== '' && ($details['projectId'] ?? '') !== $projectId) {
        return null;
    }
    
    // Проверка CRM-связи
    $crmLinks = is_array($details['crmLinks'] ?? null) ? $details['crmLinks'] : [];
    if (!$this->hasDealLink($crmLinks, $dealPrefix)) {
        return null;
    }
    
    // Проверка файлов
    $fileIds = $details['fileIds'] ?? [];
    if (!is_array($fileIds) || empty($fileIds)) {
        return null;
    }
    
    // Проверка сообщения
    $message = (string) ($details['message'] ?? '');
    if ($message === '') {
        return null;
    }
    
    // Проверка каждого типа Activity
    foreach ($configService->getActivityTypes() as $activityType) {
        if ($configService->messageHasActivityKeyword($message, $activityType)) {
            return $activityType;
        }
    }
    
    return null;
}
```

### Пример 4: Обновленный ActivityProcessor

```php
<?php
// ActivityProcessor::process()

public function process(
    array $commentDetails,
    string $activityType,
    string $entityId,
    callable $restCall
): array {
    // Валидация...
    
    // Получение поля сделки для типа Activity
    $dealField = $this->taskDetails->getActivityDealField($activityType);
    if ($dealField === '') {
        throw new InvalidArgumentException('Invalid activity type or deal field: ' . $activityType);
    }
    
    // Прикрепление файлов к задаче
    $taskAttach = $this->taskFiles->attachFiles($entityId, $fileIds, $restCall);
    
    // Построение данных файлов
    $fileDataList = [];
    foreach ($fileIds as $fileId) {
        $fileData = $this->dealFiles->buildDealFileData($fileId, $restCall);
        if ($fileData !== null) {
            $fileDataList[] = ['fileData' => $fileData];
        }
    }
    
    // Обновление файлов в сделках (с правильным полем)
    $dealUpdates = [];
    foreach ($dealIds as $dealId) {
        $dealUpdates[] = array_merge(
            ['dealId' => $dealId],
            $this->dealFiles->updateDealFiles($dealId, $dealField, $fileDataList, $restCall)
        );
    }
    
    return [
        'activityType' => $activityType,
        'dealField' => $dealField,
        'dealIds' => $dealIds,
        'fileIds' => $fileIds,
        'taskAttach' => $taskAttach,
        'dealUpdates' => $dealUpdates,
    ];
}
```

## Тестирование

1. **Тест типа Activity "Обложка":**
   - Создать комментарий с ключевым словом "обложка"
   - Проверить, что `activityType = 'cover'`
   - Проверить, что файлы обновлены в поле `UF_CRM_1769520892`

2. **Тест типа Activity "Согласованный бланк":**
   - Создать комментарий с ключевым словом "Согласованный бланк"
   - Проверить, что `activityType = 'approved_form'`
   - Проверить, что файлы обновлены в поле `UF_CRM_1759233362672`

3. **Тест обработки опечаток:**
   - Создать комментарий с "Согласованый бланк" (одна "н")
   - Проверить, что опечатка обработана корректно
   - Проверить, что `activityType = 'approved_form'`

4. **Тест регистронезависимости:**
   - Создать комментарии с разными регистрами ключевых слов
   - Проверить, что все варианты работают корректно

5. **Тест расширяемости:**
   - Добавить новый тип Activity в конфигурацию
   - Проверить, что он работает без изменения кода

## История правок

- 2026-01-27 (UTC+3, Брест): Создана задача на модернизацию ActivityFirst
