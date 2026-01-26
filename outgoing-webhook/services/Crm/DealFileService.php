<?php
declare(strict_types=1);

class DealFileService
{
    private FilesystemService $filesystem;
    private RequestService $request;
    private TaskFilesService $taskFiles;

    public function __construct(FilesystemService $filesystem, RequestService $request, TaskFilesService $taskFiles)
    {
        $this->filesystem = $filesystem;
        $this->request = $request;
        $this->taskFiles = $taskFiles;
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

        // Если имени в метаданных нет — используем fallback
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

    public function buildDealFileData(string $fileId, callable $restCall): ?array
    {
        $info = $this->taskFiles->getDiskFileInfo($fileId, $restCall);
        if ($info === null) {
            return null;
        }

        $downloadUrl = $info['downloadUrl'] ?? $info['DOWNLOAD_URL'] ?? null;
        if (!is_string($downloadUrl) || $downloadUrl === '') {
            return null;
        }

        $name = $this->resolveFileName($info, 'file_' . $fileId, $downloadUrl);
        $downloadUrl = $this->request->resolveAbsoluteUrl($downloadUrl);
        $base64 = $this->filesystem->downloadBase64($downloadUrl);
        if ($base64 === null) {
            return null;
        }

        return [$name, $base64];
    }

    public function getDealFileField(string $dealId, string $field, callable $restCall): array
    {
        $result = $restCall('crm.deal.get', ['id' => (int) $dealId]);
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
                $normalized[] = [
                    'id' => $item['id'] ?? ($item['ID'] ?? null),
                    'downloadUrl' => $item['downloadUrl'] ?? ($item['DOWNLOAD_URL'] ?? null),
                    'showUrl' => $item['showUrl'] ?? ($item['SHOW_URL'] ?? null),
                ];
                continue;
            }
            if (is_scalar($item)) {
                $normalized[] = [
                    'id' => (string) $item,
                    'downloadUrl' => null,
                    'showUrl' => null,
                ];
            }
        }

        return $normalized;
    }

    public function buildFromDealEntry(array $entry, callable $restCall): ?array
    {
        // ID из поля сделки (например, 45451) - это НЕ ID файла в disk
        // Это ID записи в поле сделки, поэтому disk.file.get не работает
        // Нужно загружать файл через downloadUrl и определять имя по содержимому или другим способом
        
        $downloadUrl = $entry['downloadUrl'] ?? $entry['DOWNLOAD_URL'] ?? null;
        if (!is_string($downloadUrl) || $downloadUrl === '') {
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
        if ($realFileId !== null) {
            $info = $this->taskFiles->getDiskFileInfo((string) $realFileId, $restCall);
        }
        
        // Если не получилось, пробуем по ID из entry
        if ($info === null) {
            $fileId = $entry['id'] ?? null;
            if ($fileId !== null && is_numeric($fileId)) {
                $info = $this->taskFiles->getDiskFileInfo((string) $fileId, $restCall);
            }
        }

        // Загружаем файл через downloadUrl
        $resolvedUrl = $this->request->resolveAbsoluteUrl($downloadUrl);
        $base64 = $this->filesystem->downloadBase64($resolvedUrl);
        if ($base64 === null) {
            return null;
        }

        // Определяем имя файла
        $name = null;
        
        // Если получили информацию из disk.file.get, используем её
        if ($info !== null) {
            $name = $this->resolveFileName($info, 'file_' . ($realFileId ?? $entry['id'] ?? 'unknown'), $resolvedUrl);
        } else {
            // Fallback: определяем имя и расширение по содержимому файла
            $mimeType = $this->detectMimeTypeFromBase64($base64);
            $ext = $mimeType !== null ? $this->getExtensionFromMimeType($mimeType) : null;
            
            // Пытаемся определить имя из URL (если не подозрительное)
            $path = parse_url($resolvedUrl, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $basename = basename($path);
                $urlExt = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
                // Если имя из URL подозрительное (show_file.php), не используем его
                if ($basename !== '' && !in_array($urlExt, ['php', 'html', 'htm', 'js', 'css'], true)) {
                    $name = $basename;
                    // Если расширения нет, но мы определили его по содержимому - добавляем
                    if ($ext !== null && pathinfo($name, PATHINFO_EXTENSION) === '') {
                        $name .= '.' . $ext;
                    }
                }
            }
            
            // Если имя не определили, создаём с правильным расширением
            if ($name === null || $name === '') {
                $id = $realFileId ?? $entry['id'] ?? 'file';
                $name = 'deal_file_' . $id;
                if ($ext !== null) {
                    $name .= '.' . $ext;
                }
            } elseif ($ext !== null) {
                // Если имя есть, но расширение неправильное или отсутствует - исправляем
                $currentExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($currentExt === '' || ($currentExt !== $ext && in_array($currentExt, ['php', 'html', 'htm'], true))) {
                    $nameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
                    $name = $nameWithoutExt . '.' . $ext;
                }
            }
        }

        return [$name, $base64];
    }

    private function detectMimeTypeFromBase64(string $base64): ?string
    {
        // Декодируем первые байты для определения MIME-типа
        $data = @base64_decode(substr($base64, 0, 100), true);
        if ($data === false || strlen($data) < 4) {
            return null;
        }
        
        // Определяем MIME-тип по magic bytes
        $magicBytes = substr($data, 0, 12);
        
        // PNG
        if (substr($magicBytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'image/png';
        }
        
        // JPEG
        if (substr($magicBytes, 0, 2) === "\xFF\xD8") {
            return 'image/jpeg';
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

    public function updateDealFiles(string $dealId, string $field, array $fileDataList, callable $restCall): array
    {
        $existingIds = $this->getDealFileField($dealId, $field, $restCall);
        $payload = [];
        $errors = [];

        // Перезагружаем существующие файлы через Base64 с правильными именами
        // Это необходимо, так как Bitrix24 требует все файлы в формате ['fileData' => [имя, base64]]
        foreach ($existingIds as $entry) {
            if (!is_array($entry)) {
                // Если это просто ID (скаляр), пропускаем (не можем получить информацию)
                $errors[] = ['fileId' => (string) $entry, 'error' => 'scalar_id_not_supported'];
                continue;
            }
            
            $fileData = $this->buildFromDealEntry($entry, $restCall);
            if ($fileData === null) {
                $errors[] = ['fileId' => $entry['id'] ?? 'unknown', 'error' => 'failed_to_load_existing_file'];
                continue;
            }
            $payload[] = ['fileData' => $fileData];
        }

        // Новые файлы добавляем в формате ['fileData' => [имя, base64]]
        foreach ($fileDataList as $item) {
            if (is_array($item) && isset($item['fileData'])) {
                $payload[] = $item;
            }
        }

        $result = $restCall('crm.deal.update', [
            'id' => (int) $dealId,
            'fields' => [
                $field => $payload,
            ],
        ]);

        if (!is_array($result) || !empty($result['error'])) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'unknown',
                'fileErrors' => $errors,
            ];
        }

        return ['success' => true, 'fileErrors' => $errors];
    }
}
