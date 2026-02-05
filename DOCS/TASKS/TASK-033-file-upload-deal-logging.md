# TASK-033: Модернизация логов загрузки файла в Сделку

**Дата создания:** 2026-02-05 22:00 (UTC+3, Брест)  
**Статус:** В работе  
**Приоритет:** Высокий  
**Исполнитель:** Bitrix24 Программист (Vanilla JS)

## Описание

Модернизация системы логирования процесса загрузки файлов в поля сделок через Activity. Проблема: файлы теряют расширение при сохранении в сделку, особенно при восстановлении существующих файлов. Необходимо улучшить логирование для диагностики причин потери расширения файлов.

## Контекст

При обработке Activity (события `ONCRMCOMMENTADD`) файлы из комментариев задач загружаются в поля сделок через `DealFileService`. Проблема проявляется в следующих случаях:

1. **Существующие файлы в сделке** — при дозагрузке новых файлов существующие теряют расширение (например, `deal_file_49941` вместо `deal_file_49941.png`)
2. **Новые файлы** — иногда теряют расширение при первой загрузке
3. **Файлы из задач** — в задаче файлы имеют правильное расширение (`chrome_TvVQ95dpSD.png`), но в сделке могут потерять его

**Текущая ситуация:**
- В задаче: `chrome_TvVQ95dpSD.png`, `chrome_nR7t5ANHuL.png`
- В сделке: `deal_file_49941` (без расширения), `chrome_nR7t5ANHuL.png` (с расширением)

## Проблема

Недостаточное логирование процесса определения расширения файлов приводит к невозможности диагностики причин потери расширения. Текущее логирование:
- Логирует только случаи полного провала определения расширения
- Не логирует промежуточные этапы (что вернул `disk.file.get`, какой MIME-тип определился, что извлечено из URL)
- Не логирует различия между новыми и существующими файлами

## Цель

Создать детальную систему логирования процесса загрузки файлов в сделки, которая позволит:
1. Отслеживать каждый этап определения расширения файла
2. Диагностировать причины потери расширения
3. Видеть различия в обработке новых и существующих файлов
4. Анализировать данные из `disk.file.get` и `crm.deal.get`

## Модули и компоненты

- `outgoing-webhook/services/Crm/DealFileService.php` — основной сервис работы с файлами сделок
  - `buildDealFileData()` — построение данных файла для новой загрузки
  - `buildFromDealEntry()` — восстановление существующего файла из записи сделки
  - `resolveFileName()` — определение имени файла из метаданных
  - `detectMimeTypeFromBase64()` — определение MIME-типа по содержимому
  - `getExtensionFromMimeType()` — получение расширения из MIME-типа
  - `updateDealFiles()` — обновление файлов в сделке

- `outgoing-webhook/services/Task/TaskFilesService.php` — сервис работы с файлами задач
  - `getDiskFileInfo()` — получение информации о файле через `disk.file.get`

- `outgoing-webhook/services/Task/Comment/ActivityProcessor.php` — обработчик Activity
  - `process()` — основная логика обработки Activity

- `outgoing-webhook/services/Error/ErrorService.php` — сервис логирования ошибок

## Зависимости

- Использует `ErrorService` для логирования
- Зависит от `TaskFilesService` для получения информации о файлах
- Использует методы Bitrix24 REST API:
  - `disk.file.get` — получение информации о файле
  - `crm.deal.get` — получение существующих файлов в сделке
  - `crm.deal.update` — обновление файлов в сделке

## Ступенчатые подзадачи

1. **Анализ текущего логирования**
   - Изучить существующие точки логирования в `DealFileService`
   - Определить недостающие этапы для логирования
   - Составить список данных, которые нужно логировать

2. **Создание структуры детального лога**
   - Определить формат лога (JSON для структурированности)
   - Создать уровни логирования (debug, info, warning, error)
   - Определить контекстные данные для каждого этапа

3. **Добавление логирования в `buildDealFileData()`**
   - Логировать данные из `disk.file.get` (NAME, ORIGINAL_NAME, CONTENT_TYPE, SIZE)
   - Логировать результат `resolveFileName()` (имя до и после обработки)
   - Логировать процесс определения расширения (MIME-тип, расширение из URL, финальное расширение)
   - Логировать финальное имя файла перед возвратом

4. **Добавление логирования в `buildFromDealEntry()`**
   - Логировать данные из записи сделки (entryName, entryId, downloadUrl)
   - Логировать попытки получения информации через `disk.file.get`
   - Логировать процесс определения расширения для существующих файлов
   - Логировать финальное имя файла после всех проверок

5. **Добавление логирования в `updateDealFiles()`**
   - Логировать количество существующих файлов
   - Логировать процесс восстановления каждого существующего файла
   - Логировать финальный payload перед отправкой в `crm.deal.update`
   - Логировать результат обновления сделки

6. **Добавление логирования в `ActivityProcessor::process()`**
   - Логировать список файлов для обработки
   - Логировать результат обработки каждого файла
   - Логировать финальный результат для метрик

7. **Создание утилиты для анализа логов**
   - Скрипт для фильтрации логов по fileId, dealId, taskId
   - Скрипт для поиска файлов без расширения
   - Скрипт для анализа причин потери расширения

## API-методы Bitrix24

- `disk.file.get` — получение информации о файле
  - Документация: https://context7.com/bitrix24/rest/disk.file.get/
  - Возвращает: ID, NAME, ORIGINAL_NAME, CONTENT_TYPE, SIZE, DOWNLOAD_URL

- `crm.deal.get` — получение сделки с файлами
  - Документация: https://context7.com/bitrix24/rest/crm.deal.get/
  - Возвращает поля файлов (UF_CRM_*) с массивом файлов

- `crm.deal.update` — обновление файлов в сделке
  - Документация: https://context7.com/bitrix24/rest/crm.deal.update/
  - Принимает fileData в формате `[имя, base64]`

## Технические требования

### Формат лога

```json
{
  "timestamp": "2026-02-05T22:00:00+03:00",
  "level": "debug|info|warning|error",
  "service": "DealFileService",
  "method": "buildDealFileData|buildFromDealEntry|updateDealFiles",
  "context": {
    "fileId": "49941",
    "dealId": "12345",
    "taskId": "6789",
    "requestId": "abc123",
    "activityType": "approved_form"
  },
  "data": {
    // Специфичные данные для каждого этапа
  },
  "result": {
    "fileName": "deal_file_49941.png",
    "hasExtension": true,
    "extension": "png",
    "extensionSource": "mime_type|url|metadata"
  }
}
```

### Уровни логирования

- **debug** — детальная информация о каждом этапе (данные из API, промежуточные результаты)
- **info** — успешные операции (файл обработан, расширение определено)
- **warning** — предупреждения (расширение не определено, используется fallback)
- **error** — ошибки (не удалось загрузить файл, не удалось определить расширение)

### Данные для логирования

#### В `buildDealFileData()`:
- Данные из `disk.file.get` (все ключи)
- Результат `resolveFileName()` (до и после)
- MIME-тип из метаданных и из содержимого
- Расширение из MIME-типа и из URL
- Финальное имя файла

#### В `buildFromDealEntry()`:
- Данные из записи сделки (entry)
- Попытки получения информации через `disk.file.get`
- Результат определения расширения
- Финальное имя файла

#### В `updateDealFiles()`:
- Количество существующих файлов
- Процесс восстановления каждого файла
- Финальный payload (имена всех файлов)
- Результат обновления

## Критерии приёмки

- [ ] Добавлено детальное логирование в `buildDealFileData()`
- [ ] Добавлено детальное логирование в `buildFromDealEntry()`
- [ ] Добавлено логирование в `updateDealFiles()`
- [ ] Логи содержат все необходимые данные для диагностики
- [ ] Создана утилита для анализа логов
- [ ] Логи позволяют определить причину потери расширения
- [ ] Логи не перегружают систему (оптимизированы по объёму)
- [ ] Логи структурированы (JSON формат)

## Примеры логов

### Успешное определение расширения

```json
{
  "timestamp": "2026-02-05T22:00:00+03:00",
  "level": "info",
  "service": "DealFileService",
  "method": "buildDealFileData",
  "context": {
    "fileId": "49941",
    "taskId": "6789",
    "requestId": "abc123"
  },
  "data": {
    "diskFileInfo": {
      "ID": "49941",
      "NAME": "chrome_TvVQ95dpSD.png",
      "ORIGINAL_NAME": "chrome_TvVQ95dpSD.png",
      "CONTENT_TYPE": "image/png",
      "SIZE": "123456"
    },
    "resolveFileNameResult": "chrome_TvVQ95dpSD.png",
    "mimeTypeFromMetadata": "image/png",
    "mimeTypeFromContent": "image/png",
    "extensionFromMime": "png",
    "extensionFromUrl": null
  },
  "result": {
    "fileName": "chrome_TvVQ95dpSD.png",
    "hasExtension": true,
    "extension": "png",
    "extensionSource": "metadata"
  }
}
```

### Потеря расширения (fallback)

```json
{
  "timestamp": "2026-02-05T22:00:00+03:00",
  "level": "warning",
  "service": "DealFileService",
  "method": "buildDealFileData",
  "context": {
    "fileId": "49941",
    "taskId": "6789",
    "requestId": "abc123"
  },
  "data": {
    "diskFileInfo": {
      "ID": "49941",
      "NAME": null,
      "ORIGINAL_NAME": null,
      "CONTENT_TYPE": null,
      "SIZE": "123456"
    },
    "resolveFileNameResult": "file_49941",
    "mimeTypeFromMetadata": null,
    "mimeTypeFromContent": "image/png",
    "extensionFromMime": "png",
    "extensionFromUrl": null,
    "finalCheck": {
      "currentExt": "",
      "detectedExt": "png",
      "addedExtension": true
    }
  },
  "result": {
    "fileName": "file_49941.png",
    "hasExtension": true,
    "extension": "png",
    "extensionSource": "mime_type"
  }
}
```

### Ошибка определения расширения

```json
{
  "timestamp": "2026-02-05T22:00:00+03:00",
  "level": "error",
  "service": "DealFileService",
  "method": "buildDealFileData",
  "context": {
    "fileId": "49941",
    "taskId": "6789",
    "requestId": "abc123"
  },
  "data": {
    "diskFileInfo": {
      "ID": "49941",
      "NAME": null,
      "ORIGINAL_NAME": null,
      "CONTENT_TYPE": null
    },
    "resolveFileNameResult": "file_49941",
    "mimeTypeFromMetadata": null,
    "mimeTypeFromContent": null,
    "extensionFromMime": null,
    "extensionFromUrl": null,
    "downloadUrl": "/bitrix/components/bitrix/disk.file.show/show_file.php?fileId=49941"
  },
  "result": {
    "fileName": "file_49941",
    "hasExtension": false,
    "extension": null,
    "extensionSource": null,
    "error": "Не удалось определить расширение файла"
  }
}
```

## Тестирование

1. **Тест с файлами, имеющими расширение в метаданных**
   - Загрузить файл с правильным именем в `disk.file.get`
   - Проверить, что лог содержит все этапы определения расширения
   - Убедиться, что расширение сохраняется

2. **Тест с файлами без расширения в метаданных**
   - Загрузить файл без имени в `disk.file.get`
   - Проверить, что лог показывает процесс определения расширения из содержимого
   - Убедиться, что расширение добавляется

3. **Тест с существующими файлами**
   - Загрузить файл в сделку
   - Добавить новый файл в ту же сделку
   - Проверить, что существующий файл не теряет расширение
   - Проверить логи восстановления существующего файла

4. **Тест анализа логов**
   - Запустить утилиту анализа логов
   - Проверить фильтрацию по fileId, dealId, taskId
   - Проверить поиск файлов без расширения

## История правок

- 2026-02-05 22:00 (UTC+3, Брест): Создана задача
- 2026-02-05 22:30 (UTC+3, Брест): Реализовано детальное логирование
  - Создан метод `logFileProcessing()` в `DealFileService`
  - Добавлено логирование в `buildDealFileData()` с детальной информацией о каждом этапе
  - Добавлено логирование в `buildFromDealEntry()` для существующих файлов
  - Добавлено логирование в `updateDealFiles()` для отслеживания процесса обновления
  - Обновлены вызовы методов для передачи контекста (requestId, taskId, activityType)
  - Создана утилита `analyze-file-logs.php` для анализа логов
