<?php
declare(strict_types=1);

/**
 * Сервис работы с файлами в полях сделки (CRM).
 *
 * Важно: файлы в задачах (чат, скрепка) и файлы в сделке — разные хранилища Bitrix24.
 * В поле сделки (UF_CRM_*) нельзя подставить fileId из задачи; нужен контент файла
 * в формате fileData ([имя, base64]) для crm.deal.update.
 * Здесь: получаем файл по ID (disk.file.get), по downloadUrl качаем контент, отдаём base64.
 */
class DealFileService
{
    private FilesystemService $filesystem;
    private RequestService $request;
    private TaskFilesService $taskFiles;
    private ErrorService $errors;

    public function __construct(
        FilesystemService $filesystem,
        RequestService $request,
        TaskFilesService $taskFiles,
        ErrorService $errors
    ) {
        $this->filesystem = $filesystem;
        $this->request = $request;
        $this->taskFiles = $taskFiles;
        $this->errors = $errors;
    }

    /**
     * Детальное логирование процесса обработки файлов для диагностики
     * 
     * @param string $level Уровень логирования (debug, info, warning, error)
     * @param string $method Название метода (buildDealFileData, buildFromDealEntry, updateDealFiles)
     * @param array $context Контекстные данные (fileId, dealId, taskId, requestId, activityType)
     * @param array $data Данные для логирования (специфичные для каждого этапа)
     * @param array $result Результат обработки (fileName, hasExtension, extension, extensionSource)
     */
    private function logFileProcessing(
        string $level,
        string $method,
        array $context = [],
        array $data = [],
        array $result = []
    ): void {
        $logEntry = [
            'timestamp' => $this->request->now(),
            'level' => $level,
            'service' => 'DealFileService',
            'method' => $method,
            'context' => $context,
            'data' => $data,
            'result' => $result,
        ];

        $logDir = dirname(__DIR__, 2) . '/logs/file-processing';
        // Создаём директорию, если её нет
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logPath = $logDir . '/file-processing-' . date('Ymd') . '.log';
        $json = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if ($json !== false) {
            $this->filesystem->appendLine($logPath, $json);
        }
        
        // Также логируем через ErrorService для критичных случаев
        if ($level === 'error' || $level === 'warning') {
            $this->errors->log("DealFileService::{$method} — {$level}", [
                'context' => $context,
                'data' => $data,
                'result' => $result,
            ]);
        }
    }

    private function resolveFileName(array $info, string $fallback, ?string $downloadUrl = null): string
    {
        // disk.file.get обычно отдаёт имя в верхнем регистре (NAME),
        // но встречаются и другие варианты ключей.
        // Приоритет: ORIGINAL_NAME > originalName > NAME > name
        $name = $info['ORIGINAL_NAME']
            ?? $info['originalName']
            ?? $info['NAME']
            ?? $info['name']
            ?? null;

        $name = is_string($name) ? trim($name) : '';
        
        // Если имя из метаданных есть, используем его
        if ($name !== '') {
            $currentExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            
            // Проверка: если имя заканчивается на подозрительное расширение (.php, .html и т.д.),
            // но MIME-тип указывает на изображение или другой тип файла, исправляем расширение
            $suspiciousExts = ['php', 'html', 'htm', 'js', 'css', 'txt'];
            if (in_array($currentExt, $suspiciousExts, true)) {
                // Пытаемся определить правильный тип по MIME-типу
                $mimeType = $info['CONTENT_TYPE'] ?? $info['contentType'] ?? $info['TYPE'] ?? $info['type'] ?? null;
                if (is_string($mimeType) && $mimeType !== '') {
                    $correctExt = $this->getExtensionFromMimeType($mimeType);
                    if ($correctExt !== null && $correctExt !== $currentExt) {
                        // Заменяем расширение на правильное
                        $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
                        $name = $nameWithoutExt . '.' . $correctExt;
                    }
                }
            }
            
            // Если расширения нет — пробуем добавить расширение из downloadUrl
            // НО только расширение, не заменяем всё имя
            if (pathinfo($name, PATHINFO_EXTENSION) === '' && is_string($downloadUrl) && $downloadUrl !== '') {
                $path = parse_url($downloadUrl, PHP_URL_PATH);
                if (is_string($path) && $path !== '') {
                    $basename = basename($path);
                    $ext = pathinfo($basename, PATHINFO_EXTENSION);
                    // Добавляем расширение только если оно есть и не подозрительное
                    if ($ext !== '' && !in_array(strtolower($ext), $suspiciousExts, true)) {
                        $name .= '.' . $ext;
                    }
                }
            }
            
            return $name;
        }

        // Если имени в метаданных нет — используем fallback, но пытаемся добавить расширение
        // Пытаемся определить расширение из MIME-типа
        $mimeType = $info['CONTENT_TYPE'] ?? $info['contentType'] ?? $info['TYPE'] ?? $info['type'] ?? null;
        $ext = null;
        if (is_string($mimeType) && $mimeType !== '') {
            $ext = $this->getExtensionFromMimeType($mimeType);
        }
        
        // Если расширение не определили из MIME-типа, пробуем из downloadUrl
        if ($ext === null && is_string($downloadUrl) && $downloadUrl !== '') {
            $path = parse_url($downloadUrl, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $basename = basename($path);
                $urlExt = pathinfo($basename, PATHINFO_EXTENSION);
                $suspiciousExts = ['php', 'html', 'htm', 'js', 'css', 'txt'];
                if ($urlExt !== '' && !in_array(strtolower($urlExt), $suspiciousExts, true)) {
                    $ext = $urlExt;
                }
            }
        }
        
        // Добавляем расширение к fallback, если определили
        if ($ext !== null) {
            // Проверяем, нет ли уже расширения в fallback
            $fallbackExt = strtolower(pathinfo($fallback, PATHINFO_EXTENSION));
            if ($fallbackExt === '' || in_array($fallbackExt, ['php', 'html', 'htm', 'js', 'css'], true)) {
                return $fallback . '.' . $ext;
            }
        }
        
        return $fallback;
    }

    private function getExtensionFromMimeType(string $mimeType): ?string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
        ];
        
        $mimeType = strtolower(trim($mimeType));
        // Убираем параметры после точки с запятой (например, "image/jpeg; charset=utf-8")
        $mimeType = explode(';', $mimeType)[0];
        $mimeType = trim($mimeType);
        
        return $mimeToExt[$mimeType] ?? null;
    }

    /**
     * Собрать данные файла для записи в поле сделки: [имя, base64].
     * fileId — ID файла диска (из комментария в задаче). В сделку по ID не подставляется.
     */
    public function buildDealFileData(string $fileId, callable $restCall, array $context = []): ?array
    {
        // Логируем начало обработки
        $this->logFileProcessing('debug', 'buildDealFileData', array_merge($context, ['fileId' => $fileId]), [
            'step' => 'start',
            'fileId' => $fileId,
        ]);

        $info = $this->taskFiles->getDiskFileInfo($fileId, $restCall);
        if ($info === null) {
            $this->logFileProcessing('error', 'buildDealFileData', array_merge($context, ['fileId' => $fileId]), [
                'step' => 'disk_file_get',
                'error' => 'disk.file.get вернул пустой результат',
            ], [
                'fileName' => null,
                'hasExtension' => false,
                'error' => 'disk_file_get_failed',
            ]);
            $this->errors->log('DealFileService::buildDealFileData — disk.file.get вернул пустой результат', [
                'fileId' => $fileId,
            ]);
            return null;
        }

        // Логируем данные из disk.file.get
        $diskFileInfo = [
            'ID' => $info['ID'] ?? $info['id'] ?? null,
            'NAME' => $info['NAME'] ?? $info['name'] ?? null,
            'ORIGINAL_NAME' => $info['ORIGINAL_NAME'] ?? $info['originalName'] ?? null,
            'CONTENT_TYPE' => $info['CONTENT_TYPE'] ?? $info['contentType'] ?? $info['TYPE'] ?? $info['type'] ?? null,
            'SIZE' => $info['SIZE'] ?? $info['size'] ?? null,
            'DOWNLOAD_URL' => $info['DOWNLOAD_URL'] ?? $info['downloadUrl'] ?? null,
        ];
        $this->logFileProcessing('debug', 'buildDealFileData', array_merge($context, ['fileId' => $fileId]), [
            'step' => 'disk_file_get_result',
            'diskFileInfo' => $diskFileInfo,
            'allKeys' => array_keys($info),
        ]);

        $downloadUrl = $info['downloadUrl'] ?? $info['DOWNLOAD_URL'] ?? null;
        if (!is_string($downloadUrl) || $downloadUrl === '') {
            $this->logFileProcessing('error', 'buildDealFileData', array_merge($context, ['fileId' => $fileId]), [
                'step' => 'download_url_check',
                'error' => 'нет downloadUrl у файла',
                'diskFileInfo' => $diskFileInfo,
            ], [
                'fileName' => null,
                'hasExtension' => false,
                'error' => 'download_url_missing',
            ]);
            $this->errors->log('DealFileService::buildDealFileData — нет downloadUrl у файла', [
                'fileId' => $fileId,
                'infoKeys' => array_keys($info),
            ]);
            return null;
        }

        $nameBeforeResolve = null;
        $name = $this->resolveFileName($info, 'file_' . $fileId, $downloadUrl);
        $nameAfterResolve = $name;
        
        $this->logFileProcessing('debug', 'buildDealFileData', array_merge($context, ['fileId' => $fileId]), [
            'step' => 'resolve_file_name',
            'resolveFileNameResult' => $name,
            'fallback' => 'file_' . $fileId,
        ]);

        $resolvedUrl = $this->request->resolveAbsoluteUrl($downloadUrl);
        $base64 = $this->filesystem->downloadBase64($resolvedUrl);
        if ($base64 === null) {
            $this->logFileProcessing('error', 'buildDealFileData', array_merge($context, ['fileId' => $fileId]), [
                'step' => 'download_file',
                'error' => 'не удалось загрузить файл по URL',
                'downloadUrl' => $resolvedUrl,
            ], [
                'fileName' => $name,
                'hasExtension' => false,
                'error' => 'download_failed',
            ]);
            $this->errors->log('DealFileService::buildDealFileData — не удалось загрузить файл по URL (base64 null)', [
                'fileId' => $fileId,
                'downloadUrl' => $resolvedUrl,
            ]);
            return null;
        }

        // Не допускаем запись HTML (страница входа/ошибки) вместо файла
        $raw = @base64_decode($base64, true);
        if ($raw !== false && preg_match('/^\s*<\s*\!?DOCTYPE\s|^\s*<\s*html\s/i', substr($raw, 0, 64))) {
            $this->logFileProcessing('error', 'buildDealFileData', array_merge($context, ['fileId' => $fileId]), [
                'step' => 'download_file',
                'error' => 'по URL вернулся HTML вместо файла (возможна страница входа)',
                'downloadUrl' => $resolvedUrl,
            ], ['fileName' => $name, 'hasExtension' => false, 'error' => 'response_is_html']);
            $this->errors->log('DealFileService::buildDealFileData — по URL вернулся HTML вместо файла', [
                'fileId' => $fileId,
                'downloadUrl' => $resolvedUrl,
            ]);
            return null;
        }

        // Финальная проверка: если имя файла всё ещё без расширения (fallback), определяем по содержимому
        $currentExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mimeTypeFromMetadata = $info['CONTENT_TYPE'] ?? $info['contentType'] ?? $info['TYPE'] ?? $info['type'] ?? null;
        $mimeTypeFromContent = null;
        $detectedExt = null;
        $extensionSource = null;
        $addedExtension = false;

        if ($currentExt === '' || in_array($currentExt, ['php', 'html', 'htm', 'js', 'css'], true)) {
            // Сначала пробуем определить MIME-тип из метаданных disk.file.get
            $mimeType = $mimeTypeFromMetadata;
            
            // Если MIME-типа нет в метаданных, определяем по содержимому файла
            if (!is_string($mimeType) || $mimeType === '') {
                $mimeType = $this->detectMimeTypeFromBase64($base64);
                $mimeTypeFromContent = $mimeType;
            }
            
            if ($mimeType !== null) {
                $detectedExt = $this->getExtensionFromMimeType($mimeType);
                if ($detectedExt !== null) {
                    $extensionSource = $mimeTypeFromMetadata ? 'metadata' : 'mime_type';
                }
            }
            
            // Если расширение не определилось из MIME-типа, пробуем из downloadUrl
            if ($detectedExt === null) {
                $path = parse_url($resolvedUrl, PHP_URL_PATH);
                if (is_string($path) && $path !== '') {
                    $basename = basename($path);
                    $urlExt = pathinfo($basename, PATHINFO_EXTENSION);
                    $suspiciousExts = ['php', 'html', 'htm', 'js', 'css'];
                    if ($urlExt !== '' && !in_array(strtolower($urlExt), $suspiciousExts, true)) {
                        $detectedExt = $urlExt;
                        $extensionSource = 'url';
                    }
                }
            }
            
            // Если расширение определилось, добавляем его к имени
            if ($detectedExt !== null) {
                $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
                $name = $nameWithoutExt . '.' . $detectedExt;
                $addedExtension = true;
            }
        } else {
            // Расширение уже есть в имени
            $extensionSource = 'metadata';
        }

        // Определяем финальный результат
        $finalExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $hasExtension = $finalExt !== '' && !in_array($finalExt, ['php', 'html', 'htm', 'js', 'css'], true);

        // Логируем финальный результат
        $logLevel = $hasExtension ? 'info' : ($detectedExt === null ? 'error' : 'warning');
        $this->logFileProcessing($logLevel, 'buildDealFileData', array_merge($context, ['fileId' => $fileId]), [
            'step' => 'final_check',
            'currentExt' => $currentExt,
            'mimeTypeFromMetadata' => $mimeTypeFromMetadata,
            'mimeTypeFromContent' => $mimeTypeFromContent,
            'extensionFromMime' => $detectedExt,
            'extensionFromUrl' => null, // Уже обработано выше
            'addedExtension' => $addedExtension,
            'finalCheck' => [
                'currentExt' => $currentExt,
                'detectedExt' => $detectedExt,
                'addedExtension' => $addedExtension,
            ],
        ], [
            'fileName' => $name,
            'hasExtension' => $hasExtension,
            'extension' => $hasExtension ? $finalExt : null,
            'extensionSource' => $extensionSource,
            'error' => $hasExtension ? null : 'Не удалось определить расширение файла',
        ]);

        if (!$hasExtension && $detectedExt === null) {
            $this->errors->log('DealFileService::buildDealFileData — не удалось определить расширение файла', [
                'fileId' => $fileId,
                'name' => $name,
                'mimeType' => $mimeTypeFromContent ?? $mimeTypeFromMetadata,
                'downloadUrl' => $resolvedUrl,
                'infoKeys' => array_keys($info),
            ]);
        }

        return [$name, $base64];
    }

    public function getDealFileField(string $dealId, string $field, callable $restCall, int $entityTypeId = 2): array
    {
        $method = $entityTypeId === 2 ? 'crm.deal.get' : 'crm.item.get';
        $payload = $entityTypeId === 2
            ? ['id' => (int) $dealId]
            : ['entityTypeId' => $entityTypeId, 'id' => (int) $dealId];

        $result = $restCall($method, $payload);
        if (!is_array($result) || !empty($result['error'])) {
            return [];
        }

        $data = $result['result'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        $value = $data[$field] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                // Сохраняем имя файла из ответа API (если есть)
                // Приоритет: ORIGINAL_NAME > originalName > NAME > name > fileName
                $fileName = $item['ORIGINAL_NAME']
                    ?? $item['originalName']
                    ?? $item['NAME']
                    ?? $item['name']
                    ?? $item['fileName']
                    ?? null;
                
                $normalized[] = [
                    'id' => $item['id'] ?? ($item['ID'] ?? null),
                    'downloadUrl' => $item['downloadUrl'] ?? ($item['DOWNLOAD_URL'] ?? null),
                    'showUrl' => $item['showUrl'] ?? ($item['SHOW_URL'] ?? null),
                    'name' => is_string($fileName) && trim($fileName) !== '' ? trim($fileName) : null,
                    'originalName' => is_string($fileName) && trim($fileName) !== '' ? trim($fileName) : null,
                ];
                continue;
            }
            if (is_scalar($item)) {
                $normalized[] = [
                    'id' => (string) $item,
                    'downloadUrl' => null,
                    'showUrl' => null,
                    'name' => null,
                    'originalName' => null,
                ];
            }
        }

        return $normalized;
    }

    public function buildFromDealEntry(array $entry, callable $restCall, array $context = []): ?array
    {
        $entryId = $entry['id'] ?? 'unknown';
        
        // Логируем начало обработки существующего файла
        $this->logFileProcessing('debug', 'buildFromDealEntry', array_merge($context, ['entryId' => $entryId]), [
            'step' => 'start',
            'entry' => [
                'id' => $entryId,
                'name' => $entry['name'] ?? null,
                'originalName' => $entry['originalName'] ?? null,
                'downloadUrl' => $entry['downloadUrl'] ?? $entry['DOWNLOAD_URL'] ?? null,
            ],
        ]);

        // Попробуем сохранить имя из записи поля сделки, если оно там есть
        // Приоритет: name > originalName > NAME > ORIGINAL_NAME > fileName
        $entryName = $entry['name']
            ?? $entry['originalName']
            ?? $entry['NAME']
            ?? $entry['ORIGINAL_NAME']
            ?? $entry['fileName']
            ?? null;
        
        $entryName = is_string($entryName) && trim($entryName) !== '' ? trim($entryName) : null;

        // ID из поля сделки (например, 45451) - это НЕ ID файла в disk
        // Это ID записи в поле сделки, поэтому disk.file.get не работает
        // Нужно загружать файл через downloadUrl и определять имя по содержимому или другим способом
        
        $downloadUrl = $entry['downloadUrl'] ?? $entry['DOWNLOAD_URL'] ?? null;
        if (!is_string($downloadUrl) || $downloadUrl === '') {
            $this->logFileProcessing('error', 'buildFromDealEntry', array_merge($context, ['entryId' => $entryId]), [
                'step' => 'download_url_check',
                'error' => 'нет downloadUrl в записи',
                'entry' => $entry,
            ], [
                'fileName' => null,
                'hasExtension' => false,
                'error' => 'download_url_missing',
            ]);
            return null;
        }

        // Пытаемся извлечь реальный ID файла из downloadUrl
        // URL вида: /bitrix/components/bitrix/crm.deal.show/show_file.php?fileId=45451
        $realFileId = null;
        if (preg_match('/[?&]fileId=(\d+)/', $downloadUrl, $matches)) {
            $realFileId = $matches[1];
        }
        
        // Пробуем получить информацию через disk.file.get по реальному ID
        $info = null;
        $diskFileGetAttempts = [];
        if ($realFileId !== null) {
            $info = $this->taskFiles->getDiskFileInfo((string) $realFileId, $restCall);
            $diskFileGetAttempts[] = ['fileId' => $realFileId, 'success' => $info !== null];
        }
        
        // Если не получилось, пробуем по ID из entry
        if ($info === null) {
            $fileId = $entry['id'] ?? null;
            if ($fileId !== null && is_numeric($fileId)) {
                $info = $this->taskFiles->getDiskFileInfo((string) $fileId, $restCall);
                $diskFileGetAttempts[] = ['fileId' => $fileId, 'success' => $info !== null];
            }
        }

        // Логируем попытки получения информации через disk.file.get
        if (!empty($diskFileGetAttempts)) {
            $this->logFileProcessing('debug', 'buildFromDealEntry', array_merge($context, ['entryId' => $entryId]), [
                'step' => 'disk_file_get_attempts',
                'attempts' => $diskFileGetAttempts,
                'hasInfo' => $info !== null,
                'info' => $info !== null ? [
                    'ID' => $info['ID'] ?? $info['id'] ?? null,
                    'NAME' => $info['NAME'] ?? $info['name'] ?? null,
                    'ORIGINAL_NAME' => $info['ORIGINAL_NAME'] ?? $info['originalName'] ?? null,
                    'CONTENT_TYPE' => $info['CONTENT_TYPE'] ?? $info['contentType'] ?? null,
                ] : null,
            ]);
        }

        // Загружаем файл через downloadUrl
        $resolvedUrl = $this->request->resolveAbsoluteUrl($downloadUrl);
        $base64 = $this->filesystem->downloadBase64($resolvedUrl);
        if ($base64 === null) {
            $this->logFileProcessing('error', 'buildFromDealEntry', array_merge($context, ['entryId' => $entryId]), [
                'step' => 'download_file',
                'error' => 'не удалось загрузить файл по URL',
                'downloadUrl' => $resolvedUrl,
            ], [
                'fileName' => null,
                'hasExtension' => false,
                'error' => 'download_failed',
            ]);
            return null;
        }

        // Определяем имя файла, стараясь сохранить исходное имя из поля сделки
        $name = null;
        $nameSource = null;
        
        // Если получили информацию из disk.file.get, используем её (самый надёжный способ)
        if ($info !== null) {
            $name = $this->resolveFileName($info, 'file_' . ($realFileId ?? $entry['id'] ?? 'unknown'), $resolvedUrl);
            $nameSource = 'disk_file_get';
        } else {
            // Fallback: используем имя из записи поля сделки (если есть)
            if ($entryName !== null) {
                $name = $entryName;
                $nameSource = 'entry_name';
            }
            
            // Если имя из записи не получилось, пытаемся определить из URL
            if ($name === null) {
                $path = parse_url($resolvedUrl, PHP_URL_PATH);
                if (is_string($path) && $path !== '') {
                    $basename = basename($path);
                    $urlExt = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
                    // Если имя из URL не подозрительное - используем его
                    if ($basename !== '' && !in_array($urlExt, ['php', 'html', 'htm', 'js', 'css'], true)) {
                        $name = $basename;
                        $nameSource = 'url';
                    }
                }
            }

            // Если имя всё ещё не определили, создаём с правильным расширением
            if ($name === null || $name === '') {
                $id = $realFileId ?? $entry['id'] ?? 'file';
                $name = 'deal_file_' . $id;
                $nameSource = 'fallback';
            }
        }
        
        // ВСЕГДА определяем MIME-тип и расширение по содержимому файла (даже если имя уже определено)
        // Это критично для случаев, когда файл был сохранён без расширения ранее
        $mimeType = null;
        $detectedExt = null;
        
        // Сначала пробуем получить MIME-тип из disk.file.get (если доступно)
        if ($info !== null) {
            $mimeType = $info['CONTENT_TYPE'] ?? $info['contentType'] ?? $info['TYPE'] ?? $info['type'] ?? null;
            if (is_string($mimeType) && $mimeType !== '') {
                $detectedExt = $this->getExtensionFromMimeType($mimeType);
            }
        }
        
        // Если не определили из метаданных, определяем по содержимому файла
        if ($detectedExt === null) {
            $mimeType = $this->detectMimeTypeFromBase64($base64);
            if ($mimeType !== null) {
                $detectedExt = $this->getExtensionFromMimeType($mimeType);
            }
        }
        
        // Если расширение не определилось из содержимого, пробуем из downloadUrl
        if ($detectedExt === null) {
            $path = parse_url($resolvedUrl, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $basename = basename($path);
                $urlExt = pathinfo($basename, PATHINFO_EXTENSION);
                $suspiciousExts = ['php', 'html', 'htm', 'js', 'css'];
                if ($urlExt !== '' && !in_array(strtolower($urlExt), $suspiciousExts, true)) {
                    $detectedExt = $urlExt;
                }
            }
        }
        
        // ВСЕГДА проверяем и исправляем расширение, даже если имя уже есть
        // Это критично для случаев, когда файл был сохранён без расширения ранее
        $currentExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $addedExtension = false;
        $extensionSource = null;
        
        if ($currentExt === '' || in_array($currentExt, ['php', 'html', 'htm', 'js', 'css'], true)) {
            // Если расширения нет или оно подозрительное - ОБЯЗАТЕЛЬНО добавляем правильное
            if ($detectedExt !== null) {
                $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
                $name = $nameWithoutExt . '.' . $detectedExt;
                $addedExtension = true;
                $extensionSource = ($info !== null && isset($info['CONTENT_TYPE'])) ? 'metadata' : ($mimeType !== null ? 'mime_type' : 'url');
            } else {
                $extensionSource = null;
            }
        } else {
            $extensionSource = $nameSource === 'disk_file_get' ? 'metadata' : ($nameSource === 'entry_name' ? 'entry' : 'url');
        }

        // Определяем финальный результат
        $finalExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $hasExtension = $finalExt !== '' && !in_array($finalExt, ['php', 'html', 'htm', 'js', 'css'], true);

        // Логируем финальный результат
        $logLevel = $hasExtension ? 'info' : ($detectedExt === null ? 'error' : 'warning');
        $this->logFileProcessing($logLevel, 'buildFromDealEntry', array_merge($context, ['entryId' => $entryId]), [
            'step' => 'final_check',
            'entryName' => $entryName,
            'nameSource' => $nameSource,
            'currentExt' => $currentExt,
            'mimeType' => $mimeType,
            'detectedExt' => $detectedExt,
            'addedExtension' => $addedExtension,
            'finalCheck' => [
                'currentExt' => $currentExt,
                'detectedExt' => $detectedExt,
                'addedExtension' => $addedExtension,
            ],
        ], [
            'fileName' => $name,
            'hasExtension' => $hasExtension,
            'extension' => $hasExtension ? $finalExt : null,
            'extensionSource' => $extensionSource,
            'error' => $hasExtension ? null : 'Не удалось определить расширение файла',
        ]);

        // КРИТИЧНО: Если расширение не определено, пытаемся использовать fallback расширение
        // Это предотвратит потерю файлов при обновлении сделки
        if (!$hasExtension && $detectedExt === null) {
            // Последняя попытка: используем расширение из имени файла в задаче (если доступно через контекст)
            // Или пробуем определить по содержимому более агрессивно
            $fallbackExt = null;
            
            // Если есть информация о файле из задачи через контекст, используем её
            if (isset($context['fileIds']) && is_array($context['fileIds']) && !empty($context['fileIds'])) {
                // Пробуем найти соответствующий fileId из задачи
                foreach ($context['fileIds'] as $taskFileId) {
                    $taskFileInfo = $this->taskFiles->getDiskFileInfo((string) $taskFileId, $restCall);
                    if (is_array($taskFileInfo)) {
                        $taskFileName = $taskFileInfo['NAME'] ?? $taskFileInfo['name'] ?? $taskFileInfo['ORIGINAL_NAME'] ?? $taskFileInfo['originalName'] ?? null;
                        if (is_string($taskFileName) && $taskFileName !== '') {
                            $taskExt = strtolower(pathinfo($taskFileName, PATHINFO_EXTENSION));
                            if ($taskExt !== '' && !in_array($taskExt, ['php', 'html', 'htm', 'js', 'css'], true)) {
                                $fallbackExt = $taskExt;
                                break;
                            }
                        }
                    }
                }
            }
            
            if ($fallbackExt === null) {
                // Если не нашли через контекст, пробуем определить по содержимому более агрессивно
                // Декодируем больше данных для определения типа
                $largerData = @base64_decode(substr($base64, 0, 500), true);
                if ($largerData !== false && strlen($largerData) >= 4) {
                    $largerMimeType = $this->detectMimeTypeFromBase64(substr($base64, 0, 500));
                    if ($largerMimeType !== null) {
                        $fallbackExt = $this->getExtensionFromMimeType($largerMimeType);
                    }
                }
            }
            
            if ($fallbackExt !== null) {
                $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
                $name = $nameWithoutExt . '.' . $fallbackExt;
                $addedExtension = true;
                $extensionSource = 'fallback_context_or_content';
                $hasExtension = true;
                
                $this->logFileProcessing('warning', 'buildFromDealEntry', array_merge($context, ['entryId' => $entryId]), [
                    'step' => 'fallback_extension_added',
                    'fallbackExt' => $fallbackExt,
                    'originalName' => $name,
                    'newName' => $name,
                ], [
                    'fileName' => $name,
                    'hasExtension' => true,
                    'extension' => $fallbackExt,
                    'extensionSource' => $extensionSource,
                ]);
            } else {
                $this->logFileProcessing('error', 'buildFromDealEntry', array_merge($context, ['entryId' => $entryId]), [
                    'step' => 'extension_detection_failed',
                    'error' => 'не удалось определить расширение файла даже через fallback',
                    'entryName' => $entryName,
                    'currentName' => $name,
                    'mimeType' => $mimeType,
                    'downloadUrl' => $resolvedUrl,
                    'hasInfo' => $info !== null,
                    'realFileId' => $realFileId,
                ], [
                    'fileName' => $name,
                    'hasExtension' => false,
                    'error' => 'extension_detection_failed',
                ]);
                
                $this->errors->log('DealFileService::buildFromDealEntry — не удалось определить расширение файла', [
                    'entryId' => $entryId,
                    'realFileId' => $realFileId,
                    'entryName' => $entryName,
                    'currentName' => $name,
                    'mimeType' => $mimeType,
                    'downloadUrl' => $resolvedUrl,
                    'hasInfo' => $info !== null,
                ]);
                
                // КРИТИЧНО: Не возвращаем файл без расширения, чтобы не потерять его в сделке
                // Вместо этого возвращаем null, чтобы процесс прервался с ошибкой
                // Это предотвратит потерю файлов при обновлении сделки
                return null;
            }
        }

        return [$name, $base64];
    }

    private function detectMimeTypeFromBase64(string $base64): ?string
    {
        // Декодируем первые байты для определения MIME-типа
        // Увеличиваем размер для более надёжного определения (до 200 символов base64 ≈ 150 байт)
        $data = @base64_decode(substr($base64, 0, 200), true);
        if ($data === false || strlen($data) < 4) {
            return null;
        }
        
        // Определяем MIME-тип по magic bytes
        $magicBytes = substr($data, 0, 16);
        
        // PNG: \x89PNG\r\n\x1a\n
        if (substr($magicBytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'image/png';
        }
        
        // JPEG: \xFF\xD8
        if (substr($magicBytes, 0, 2) === "\xFF\xD8") {
            return 'image/jpeg';
        }
        
        // GIF: GIF87a или GIF89a
        if (substr($magicBytes, 0, 6) === "GIF87a" || substr($magicBytes, 0, 6) === "GIF89a") {
            return 'image/gif';
        }
        
        // WebP: RIFF...WEBP
        if (substr($magicBytes, 0, 4) === "RIFF" && substr($magicBytes, 8, 4) === "WEBP") {
            return 'image/webp';
        }
        
        // PDF: %PDF
        if (substr($magicBytes, 0, 4) === "%PDF") {
            return 'application/pdf';
        }
        
        // GIF
        if (substr($magicBytes, 0, 6) === "GIF87a" || substr($magicBytes, 0, 6) === "GIF89a") {
            return 'image/gif';
        }
        
        // WebP
        if (substr($magicBytes, 0, 4) === "RIFF" && substr($magicBytes, 8, 4) === "WEBP") {
            return 'image/webp';
        }
        
        // PDF
        if (substr($magicBytes, 0, 4) === "%PDF") {
            return 'application/pdf';
        }
        
        // Если есть функция finfo, используем её
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = finfo_buffer($finfo, $data);
                finfo_close($finfo);
                if ($mimeType !== false && $mimeType !== '') {
                    return $mimeType;
                }
            }
        }
        
        return null;
    }

    public function updateDealFiles(
        string $dealId,
        string $field,
        array $fileDataList,
        callable $restCall,
        int $entityTypeId = 2,
        array $context = []
    ): array
    {
        // Логируем начало обновления файлов в сделке
        $this->logFileProcessing('debug', 'updateDealFiles', array_merge($context, ['dealId' => $dealId, 'field' => $field]), [
            'step' => 'start',
            'dealId' => $dealId,
            'field' => $field,
            'entityTypeId' => $entityTypeId,
            'newFilesCount' => count($fileDataList),
        ]);

        $existingIds = $this->getDealFileField($dealId, $field, $restCall, $entityTypeId);
        $payload = [];
        $errors = [];
        $existingFilesProcessed = [];

        // Логируем количество существующих файлов
        $this->logFileProcessing('debug', 'updateDealFiles', array_merge($context, ['dealId' => $dealId, 'field' => $field]), [
            'step' => 'get_existing_files',
            'existingFilesCount' => count($existingIds),
            'existingFiles' => array_map(function($entry) {
                if (is_array($entry)) {
                    return [
                        'id' => $entry['id'] ?? null,
                        'name' => $entry['name'] ?? null,
                        'downloadUrl' => $entry['downloadUrl'] ?? null,
                    ];
                }
                return ['id' => (string)$entry];
            }, $existingIds),
        ]);

        // Сохраняем существующие файлы по ID, БЕЗ перекачки по URL.
        // URL файлов в сделке (show_file.php) требует авторизации в Bitrix24;
        // при запросе с нашего сервера возвращается HTML страницы входа → в сделку попадал бы битый «файл».
        // Bitrix24 API позволяет оставить файл в поле, передав его id: [{"id": fileId}, ...]
        // См. «How to Partially Overwrite Values of a Multiple Field of Type File» в crm.item.update.
        foreach ($existingIds as $index => $entry) {
            $entryId = $entry['id'] ?? ($entry['ID'] ?? null);
            if (!is_array($entry) || $entryId === null || $entryId === '') {
                $this->logFileProcessing('warning', 'updateDealFiles', array_merge($context, ['dealId' => $dealId, 'field' => $field]), [
                    'step' => 'skip_existing_entry',
                    'reason' => 'missing_id',
                    'entry' => $entry,
                ]);
                continue;
            }
            $payload[] = ['id' => (int) $entryId];
            $existingFilesProcessed[] = [
                'entryId' => $entryId,
                'preservedById' => true,
            ];
        }

        // Новые файлы добавляем в формате ['fileData' => [имя, base64]]
        $newFilesProcessed = [];
        foreach ($fileDataList as $index => $item) {
            if (is_array($item) && isset($item['fileData'])) {
                $payload[] = $item;
                $newFilesProcessed[] = [
                    'index' => $index,
                    'fileName' => $item['fileData'][0] ?? null,
                    'hasExtension' => !empty(pathinfo($item['fileData'][0] ?? '', PATHINFO_EXTENSION)),
                ];
            }
        }

        // Логируем обработанные файлы
        $this->logFileProcessing('debug', 'updateDealFiles', array_merge($context, ['dealId' => $dealId, 'field' => $field]), [
            'step' => 'files_processed',
            'existingFilesProcessed' => $existingFilesProcessed,
            'newFilesProcessed' => $newFilesProcessed,
            'totalFilesCount' => count($payload),
        ]);

        $newFilesCount = count($fileDataList);
        if ($newFilesCount === 0) {
            $this->logFileProcessing('warning', 'updateDealFiles', array_merge($context, ['dealId' => $dealId, 'field' => $field]), [
                'step' => 'no_new_files',
                'existingCount' => count($existingIds),
            ]);
            $this->errors->log('DealFileService::updateDealFiles — нет новых файлов для записи в сделку (buildDealFileData вернул null по всем fileId)', [
                'dealId' => $dealId,
                'field' => $field,
                'existingCount' => count($existingIds),
            ]);
        }

        // Логируем финальный payload (только имена файлов, без base64)
        $payloadFileNames = array_map(function($item) {
            if (is_array($item) && isset($item['fileData']) && is_array($item['fileData'])) {
                return $item['fileData'][0] ?? null;
            }
            return null;
        }, $payload);
        
        $this->logFileProcessing('debug', 'updateDealFiles', array_merge($context, ['dealId' => $dealId, 'field' => $field]), [
            'step' => 'final_payload',
            'payloadFileNames' => $payloadFileNames,
            'payloadCount' => count($payload),
            'filesWithoutExtension' => array_filter($payloadFileNames, function($name) {
                if ($name === null) return true;
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                return $ext === '' || in_array($ext, ['php', 'html', 'htm', 'js', 'css'], true);
            }),
        ]);

        $method = $entityTypeId === 2 ? 'crm.deal.update' : 'crm.item.update';
        $updatePayload = $entityTypeId === 2
            ? [
                'id' => (int) $dealId,
                'fields' => [
                    $field => $payload,
                ],
            ]
            : [
                'entityTypeId' => $entityTypeId,
                'id' => (int) $dealId,
                'fields' => [
                    $field => $payload,
                ],
            ];

        $result = $restCall($method, $updatePayload);

        if (!is_array($result) || !empty($result['error'])) {
            $this->logFileProcessing('error', 'updateDealFiles', array_merge($context, ['dealId' => $dealId, 'field' => $field]), [
                'step' => 'crm_update',
                'error' => $result['error'] ?? 'unknown',
                'error_description' => $result['error_description'] ?? null,
                'method' => $method,
                'payloadFileNames' => $payloadFileNames,
            ], [
                'success' => false,
                'error' => $result['error'] ?? 'unknown',
            ]);
            $this->errors->log('DealFileService::updateDealFiles — webhook crm update ошибка', [
                'dealId' => $dealId,
                'field' => $field,
                'entityTypeId' => $entityTypeId,
                'error' => $result['error'] ?? 'unknown',
                'error_description' => $result['error_description'] ?? null,
                'response' => $result,
            ]);
            return [
                'success' => false,
                'error' => $result['error'] ?? 'unknown',
                'fileErrors' => $errors,
            ];
        }

        // Логируем успешное обновление
        $this->logFileProcessing('info', 'updateDealFiles', array_merge($context, ['dealId' => $dealId, 'field' => $field]), [
            'step' => 'crm_update_success',
            'method' => $method,
            'payloadFileNames' => $payloadFileNames,
            'existingFilesCount' => count($existingFilesProcessed),
            'newFilesCount' => count($newFilesProcessed),
        ], [
            'success' => true,
            'filesCount' => count($payload),
            'fileNames' => $payloadFileNames,
        ]);

        return ['success' => true, 'fileErrors' => $errors];
    }
}
