# TASK-030: Перевести Activity на вебхук (files.attach + crm.item.update)

**Дата создания:** 2026-02-04 00:00 (UTC+3, Брест)  
**Статус:** Новая  
**Приоритет:** Средний  
**Исполнитель:** Bitrix24 Программист (Vanilla JS / PHP / REST)

## Описание
Перенести цепочку Activity (прикрепление файлов к задаче и загрузка файлов в сделку) с авторизации по токену приложения на авторизацию через входящий вебхук. Остальной функционал продолжает использовать токен приложения; только Activity должна работать на вебхуке.

## Контекст
Сейчас `ActivityProcessor` и `ActivityFirstProcessor` вызывают REST через общий `$restCall` (токен приложения). Необходимо для Activity сформировать отдельный REST-коллбек на базе вебхука и использовать его для `tasks.task.files.attach`, `disk.file.get`, `crm.item.update` (или `crm.deal.update`, если сделка — классический entityTypeId=2). Идентификация события (pre-check) может идти на токене приложения, но фактическое прикрепление/загрузка файлов — только вебхук.

## Модули и компоненты
- `outgoing-webhook/services/Task/Comment/ActivityProcessor.php`
- `outgoing-webhook/services/Task/Comment/ActivityFirstProcessor.php`
- `outgoing-webhook/services/Task/TaskDetailsService.php` (инициализация rest-call)
- `outgoing-webhook/services/Task/TaskFilesService.php`
- `outgoing-webhook/services/Task/DealFileService.php`
- Конфиг для вебхука: добавить/использовать `B24_WEBHOOK_BASE` вида `https://<portal>/rest/<user_id>/<code>/`

## Зависимости
- REST Bitrix24 доступен по входящему вебхуку.
- Требуется знать `entityTypeId` сделки: классическая сделка — 2; если смарт-процесс, указать актуальный `entityTypeId`.

## Ступенчатые подзадачи
1. Добавить конфиг `B24_WEBHOOK_BASE` (ENV/config PHP) и хелпер сборки URL для REST по вебхуку.
2. Реализовать коллбек `$restCallWebhook` (POST JSON) на базе `B24_WEBHOOK_BASE`.
3. В Activity-потоке (ActivityProcessor/ActivityFirstProcessor) подменить `$restCall` на `$restCallWebhook`.
4. В `TaskFilesService`/`DealFileService` убедиться, что в Activity вызовы идут через вебхук:  
   - `tasks.task.files.attach` — прикрепление к задаче;  
   - `disk.file.get` → `downloadUrl`;  
   - `crm.item.update` (или `crm.deal.update`) — обновление поля сделки `UF_CRM_*` с `fileData`.
5. Добавить логирование “webhook-mode” (метод, статус/ошибка, taskId, dealId/entityId, activityType).
6. Добавить 1 ретрай при пустом результате/`ERROR_CORE` (задержка ~2s) — всё через вебхук.
7. Ручные проверки: файл прикреплён к задаче и попал в поле сделки, вызовы идут через вебхук (проверка логов/URL).

## API-методы Bitrix24
- `tasks.task.files.attach` — прикрепление файла к задаче.  
- `disk.file.get` — получить `downloadUrl`, имя, размер.  
- `crm.item.update` — обновление полей сделки/смарт-процесса (`entityTypeId` обязателен). Для классической сделки допустимо `crm.deal.update`.  
Документация: https://apidocs.bitrix24.ru/

## Технические требования
- В Activity строго использовать вебхук; токен приложения в этой цепочке запрещён.
- `B24_WEBHOOK_BASE` не хардкодить — брать из ENV/config.
- `crm.item.update`: передавать `entityTypeId` и `id`, поле `UF_CRM_*` — массив `[['fileData' => [$name, base64]]]`.
- Скачать файл: `disk.file.get` через вебхук → `downloadUrl` → загрузка контента → base64.
- Валидация входных данных: `taskId`, `dealId`/`entityId` — непустые; `fileIds` — массив положительных чисел.
- Логировать ошибки: HTTP-статус, `error`, `error_description`, контекст (taskId, dealId, activityType).
- Ретрай: одна повторная попытка при пустом результате или ошибке.

## Критерии приёмки
- Файл прикреплён к задаче через вебхук (`tasks.task.files.get` или UI подтверждает).
- Поле сделки обновлено через вебхук (`crm.item.get`/`crm.deal.get` показывает файл/значение).
- Логи содержат явную пометку webhook-mode; нет использования client-token в Activity.
- Остальной функционал на токене приложения не затронут.
- При неверных входных данных возвращается контролируемая ошибка без фатала.

## Примеры кода (скелет вызова вебхука)
```php
$restCallWebhook = function (string $method, array $params = []) use ($webhookBase) {
    $url = rtrim($webhookBase, '/') . '/' . $method;
    $resp = Http::withHeaders(['Content-Type' => 'application/json'])->post($url, $params);
    if ($resp->failed()) {
        throw new \RuntimeException("Webhook REST error: {$resp->status()} {$resp->body()}");
    }
    $data = $resp->json();
    if (isset($data['error'])) {
        throw new \RuntimeException("Webhook REST error: {$data['error_description']}");
    }
    return $data;
};
```

## Тестирование
1. Подготовить задачу со связью на сделку, добавить файл в комментарий.  
2. Проверить в UI/через REST, что файл прикреплён к задаче.  
3. Проверить через `crm.item.get`/`crm.deal.get`, что поле `UF_CRM_*` содержит файл.  
4. Проверить ретрай: симулировать пустой ответ, убедиться в повторной попытке.  
5. Проверить лог “webhook-mode” и отсутствие client-token в Activity-запросах.

## История правок
- 2026-02-04 00:00 — Создана задача.
