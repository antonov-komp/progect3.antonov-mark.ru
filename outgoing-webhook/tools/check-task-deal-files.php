<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';
require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

/**
 * Скрипт для проверки файлов в задаче и связанной сделке
 * 
 * Использование:
 * php check-task-deal-files.php <taskId> [dealId]
 * 
 * Пример:
 * php check-task-deal-files.php 807
 * php check-task-deal-files.php 807 12235
 */

function outgoingWebhookRestCall(string $method, array $params = []): array
{
    $client = new Bitrix24Client();
    return $client->call($method, $params);
}

function getTaskFiles(string $taskId): array
{
    $result = outgoingWebhookRestCall('task.item.getfiles', ['TASKID' => (int) $taskId]);
    if (!is_array($result) || !empty($result['error'])) {
        return [];
    }
    $files = $result['result'] ?? [];
    // Нормализуем структуру файлов
    $normalized = [];
    foreach ($files as $file) {
        if (is_array($file)) {
            $normalized[] = $file;
        } elseif (is_scalar($file)) {
            $normalized[] = ['id' => (string) $file, 'ID' => (string) $file];
        }
    }
    return $normalized;
}

function getTaskCrmLinks(string $taskId): array
{
    $result = outgoingWebhookRestCall('task.item.getdata', ['TASKID' => (int) $taskId]);
    if (!is_array($result) || !empty($result['error'])) {
        return [];
    }
    $data = $result['result'] ?? [];
    return $data['UF_CRM_TASK'] ?? [];
}

function getDealFiles(string $dealId, string $field = 'UF_CRM_1759233362672'): array
{
    $result = outgoingWebhookRestCall('crm.deal.get', ['id' => (int) $dealId]);
    if (!is_array($result) || !empty($result['error'])) {
        return [];
    }
    $data = $result['result'] ?? [];
    $files = $data[$field] ?? [];
    
    // Нормализуем структуру файлов
    $normalized = [];
    foreach ($files as $file) {
        if (is_array($file)) {
            $normalized[] = $file;
        } elseif (is_scalar($file)) {
            $normalized[] = ['id' => (string) $file, 'ID' => (string) $file];
        }
    }
    return $normalized;
}

function extractFileIdFromDownloadUrl(string $downloadUrl): ?string
{
    // Пытаемся извлечь fileId из URL вида:
    // /bitrix/components/bitrix/crm.deal.show/show_file.php?fileId=45451
    if (preg_match('/[?&]fileId=(\d+)/', $downloadUrl, $matches)) {
        return $matches[1];
    }
    return null;
}

function getDiskFileInfo(string $fileId): ?array
{
    $result = outgoingWebhookRestCall('disk.file.get', ['id' => (int) $fileId]);
    if (!is_array($result) || !empty($result['error'])) {
        return null;
    }
    return $result['result'] ?? null;
}

function extractDealIds(array $crmLinks): array
{
    $dealIds = [];
    foreach ($crmLinks as $link) {
        if (!is_string($link) || $link === '') {
            continue;
        }
        if (str_starts_with($link, 'D_')) {
            $dealId = substr($link, 2);
            if ($dealId !== '') {
                $dealIds[] = $dealId;
            }
            continue;
        }
        if (preg_match('~/crm/deal/details/(\d+)/~', $link, $matches)) {
            $dealIds[] = $matches[1];
        }
    }
    return array_values(array_unique($dealIds));
}

function formatFileInfo(array $file, ?array $diskInfo = null): array
{
    $fileId = $file['id'] ?? $file['ID'] ?? 'unknown';
    $name = $file['name'] ?? $file['NAME'] ?? null;
    $downloadUrl = $file['downloadUrl'] ?? $file['DOWNLOAD_URL'] ?? null;
    
    $info = [
        'fileId' => $fileId,
        'name_from_data' => $name,
        'downloadUrl' => $downloadUrl,
    ];
    
    if ($diskInfo !== null) {
        $info['disk_file_info'] = [
            'ORIGINAL_NAME' => $diskInfo['ORIGINAL_NAME'] ?? null,
            'originalName' => $diskInfo['originalName'] ?? null,
            'NAME' => $diskInfo['NAME'] ?? null,
            'name' => $diskInfo['name'] ?? null,
            'CONTENT_TYPE' => $diskInfo['CONTENT_TYPE'] ?? null,
            'contentType' => $diskInfo['contentType'] ?? null,
            'TYPE' => $diskInfo['TYPE'] ?? null,
            'type' => $diskInfo['type'] ?? null,
            'SIZE' => $diskInfo['SIZE'] ?? null,
            'size' => $diskInfo['size'] ?? null,
            'DOWNLOAD_URL' => $diskInfo['DOWNLOAD_URL'] ?? null,
            'downloadUrl' => $diskInfo['downloadUrl'] ?? null,
        ];
        
        // Определяем финальное имя
        $finalName = $diskInfo['ORIGINAL_NAME']
            ?? $diskInfo['originalName']
            ?? $diskInfo['NAME']
            ?? $diskInfo['name']
            ?? 'unknown';
        
        $info['final_name'] = $finalName;
        $info['extension'] = pathinfo($finalName, PATHINFO_EXTENSION);
        $info['mime_type'] = $diskInfo['CONTENT_TYPE'] ?? $diskInfo['contentType'] ?? $diskInfo['TYPE'] ?? $diskInfo['type'] ?? null;
    }
    
    return $info;
}

// Получаем параметры
$taskId = $argv[1] ?? null;
$dealId = $argv[2] ?? null;

if ($taskId === null) {
    echo "Использование: php check-task-deal-files.php <taskId> [dealId]\n";
    echo "Пример: php check-task-deal-files.php 807\n";
    echo "Пример: php check-task-deal-files.php 807 12235\n";
    exit(1);
}

echo "=== Проверка файлов в задаче и сделке ===\n\n";
echo "Задача ID: {$taskId}\n";

// Получаем файлы задачи
echo "\n--- Файлы в задаче ---\n";
$taskFiles = getTaskFiles($taskId);
if (empty($taskFiles)) {
    echo "Файлов в задаче не найдено\n";
} else {
    echo "Найдено файлов: " . count($taskFiles) . "\n\n";
    foreach ($taskFiles as $index => $file) {
        $fileId = $file['id'] ?? $file['ID'] ?? null;
        if ($fileId === null) {
            echo "Файл #" . ($index + 1) . ": ⚠️ Не удалось определить ID файла\n";
            echo "  Данные: " . json_encode($file, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            continue;
        }
        
        echo "Файл #" . ($index + 1) . " (ID: {$fileId}):\n";
        
        $diskInfo = getDiskFileInfo((string) $fileId);
        $fileInfo = formatFileInfo($file, $diskInfo);
        
        echo "  Имя из данных задачи: " . ($fileInfo['name_from_data'] ?? 'null') . "\n";
        if ($diskInfo !== null) {
            echo "  ORIGINAL_NAME: " . ($fileInfo['disk_file_info']['ORIGINAL_NAME'] ?? 'null') . "\n";
            echo "  NAME: " . ($fileInfo['disk_file_info']['NAME'] ?? 'null') . "\n";
            echo "  name: " . ($fileInfo['disk_file_info']['name'] ?? 'null') . "\n";
            echo "  Финальное имя: " . ($fileInfo['final_name'] ?? 'unknown') . "\n";
            echo "  Расширение: " . ($fileInfo['extension'] ?? 'нет') . "\n";
            echo "  MIME-тип: " . ($fileInfo['mime_type'] ?? 'неизвестно') . "\n";
            echo "  Размер: " . ($fileInfo['disk_file_info']['SIZE'] ?? $fileInfo['disk_file_info']['size'] ?? 'неизвестно') . " байт\n";
        } else {
            echo "  ⚠️ Не удалось получить информацию из disk.file.get\n";
        }
        echo "\n";
    }
}

// Получаем CRM-связи
echo "--- CRM-связи задачи ---\n";
$crmLinks = getTaskCrmLinks($taskId);
if (empty($crmLinks)) {
    echo "CRM-связей не найдено\n";
} else {
    echo "Найдено связей: " . count($crmLinks) . "\n";
    foreach ($crmLinks as $link) {
        echo "  - {$link}\n";
    }
    
    $dealIds = extractDealIds($crmLinks);
    if (!empty($dealIds)) {
        echo "\nИзвлечённые ID сделок: " . implode(', ', $dealIds) . "\n";
    }
}

// Проверяем файлы в сделке
if ($dealId !== null) {
    echo "\n--- Файлы в сделке (ID: {$dealId}) ---\n";
} else {
    if (!empty($dealIds)) {
        $dealId = $dealIds[0];
        echo "\n--- Файлы в сделке (ID: {$dealId}, первая из связанных) ---\n";
    } else {
        echo "\n--- Файлы в сделке ---\n";
        echo "⚠️ ID сделки не указан и не найден в CRM-связях\n";
        exit(0);
    }
}

$dealFiles = getDealFiles($dealId);
if (empty($dealFiles)) {
    echo "Файлов в сделке не найдено\n";
} else {
    echo "Найдено файлов: " . count($dealFiles) . "\n\n";
    foreach ($dealFiles as $index => $file) {
        echo "Файл #" . ($index + 1) . ":\n";
        echo "  Полные данные из crm.deal.get: " . json_encode($file, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        
        $fileId = null;
        if (is_array($file)) {
            $fileId = $file['id'] ?? $file['ID'] ?? null;
        } elseif (is_scalar($file)) {
            $fileId = (string) $file;
        }
        
        if ($fileId === null) {
            echo "  ⚠️ Не удалось определить ID файла\n\n";
            continue;
        }
        
        echo "  ID из данных сделки: {$fileId}\n";
        
        if (is_array($file)) {
            echo "  Имя из данных сделки: " . ($file['name'] ?? $file['NAME'] ?? 'null') . "\n";
            $downloadUrl = $file['downloadUrl'] ?? $file['DOWNLOAD_URL'] ?? null;
            echo "  downloadUrl из данных сделки: " . ($downloadUrl ?? 'null') . "\n";
            
            // Пытаемся извлечь реальный fileId из downloadUrl
            if ($downloadUrl !== null) {
                $realFileId = extractFileIdFromDownloadUrl($downloadUrl);
                if ($realFileId !== null) {
                    echo "  💡 Извлечённый fileId из URL: {$realFileId}\n";
                    if ($realFileId !== $fileId) {
                        echo "  ⚠️ ID из данных ({$fileId}) отличается от ID в URL ({$realFileId})\n";
                    }
                    // Пробуем получить информацию по обоим ID
                    echo "\n  Попытка получить информацию через disk.file.get:\n";
                    echo "    По ID из данных ({$fileId}):\n";
                    $diskInfo1 = getDiskFileInfo((string) $fileId);
                    if ($diskInfo1 !== null) {
                        echo "      ✅ Успешно\n";
                        $fileInfo = formatFileInfo($file, $diskInfo1);
                    } else {
                        echo "      ❌ ERROR_NOT_FOUND\n";
                    }
                    
                    echo "    По ID из URL ({$realFileId}):\n";
                    $diskInfo2 = getDiskFileInfo((string) $realFileId);
                    if ($diskInfo2 !== null) {
                        echo "      ✅ Успешно\n";
                        $fileInfo = formatFileInfo($file, $diskInfo2);
                        $fileId = $realFileId; // Используем реальный ID файла
                    } else {
                        echo "      ❌ ERROR_NOT_FOUND\n";
                    }
                    
                    $diskInfo = $diskInfo2 ?? $diskInfo1;
                } else {
                    $diskInfo = getDiskFileInfo((string) $fileId);
                }
            } else {
                $diskInfo = getDiskFileInfo((string) $fileId);
            }
        } else {
            $diskInfo = getDiskFileInfo((string) $fileId);
        }
        
        $fileInfo = formatFileInfo(is_array($file) ? $file : ['id' => $fileId], $diskInfo);
        
        if ($diskInfo !== null) {
            echo "  ORIGINAL_NAME: " . ($fileInfo['disk_file_info']['ORIGINAL_NAME'] ?? 'null') . "\n";
            echo "  NAME: " . ($fileInfo['disk_file_info']['NAME'] ?? 'null') . "\n";
            echo "  name: " . ($fileInfo['disk_file_info']['name'] ?? 'null') . "\n";
            echo "  Финальное имя: " . ($fileInfo['final_name'] ?? 'unknown') . "\n";
            echo "  Расширение: " . ($fileInfo['extension'] ?? 'нет') . "\n";
            echo "  MIME-тип: " . ($fileInfo['mime_type'] ?? 'неизвестно') . "\n";
            echo "  Размер: " . ($fileInfo['disk_file_info']['SIZE'] ?? $fileInfo['disk_file_info']['size'] ?? 'неизвестно') . " байт\n";
            
            // Проверка на подозрительные расширения
            $ext = strtolower($fileInfo['extension'] ?? '');
            $mimeType = strtolower($fileInfo['mime_type'] ?? '');
            $suspiciousExts = ['php', 'html', 'htm', 'js', 'css', 'txt'];
            
            if (in_array($ext, $suspiciousExts, true)) {
                echo "  ⚠️ ПОДОЗРИТЕЛЬНОЕ РАСШИРЕНИЕ: .{$ext}\n";
                if (str_starts_with($mimeType, 'image/')) {
                    $correctExt = match($mimeType) {
                        'image/jpeg', 'image/jpg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        default => 'unknown'
                    };
                    echo "  💡 MIME-тип указывает на изображение ({$mimeType}), правильное расширение должно быть: .{$correctExt}\n";
                }
            }
        } else {
            echo "  ⚠️ Не удалось получить информацию из disk.file.get\n";
        }
        echo "\n";
    }
}

// Сравнение файлов
echo "--- Сравнение файлов задачи и сделки ---\n";
if (!empty($taskFiles) && !empty($dealFiles)) {
    $taskFileIds = [];
    foreach ($taskFiles as $file) {
        $fileId = $file['id'] ?? $file['ID'] ?? null;
        if ($fileId !== null) {
            $taskFileIds[] = (string) $fileId;
        }
    }
    
    $dealFileIds = [];
    foreach ($dealFiles as $file) {
        $fileId = null;
        if (is_array($file)) {
            $fileId = $file['id'] ?? $file['ID'] ?? null;
        } elseif (is_scalar($file)) {
            $fileId = (string) $file;
        }
        if ($fileId !== null) {
            $dealFileIds[] = (string) $fileId;
        }
    }
    
    $commonFiles = array_intersect($taskFileIds, $dealFileIds);
    $onlyInTask = array_diff($taskFileIds, $dealFileIds);
    $onlyInDeal = array_diff($dealFileIds, $taskFileIds);
    
    echo "Общих файлов: " . count($commonFiles) . "\n";
    if (!empty($commonFiles)) {
        echo "  ID: " . implode(', ', $commonFiles) . "\n";
    }
    
    echo "Только в задаче: " . count($onlyInTask) . "\n";
    if (!empty($onlyInTask)) {
        echo "  ID: " . implode(', ', $onlyInTask) . "\n";
    }
    
    echo "Только в сделке: " . count($onlyInDeal) . "\n";
    if (!empty($onlyInDeal)) {
        echo "  ID: " . implode(', ', $onlyInDeal) . "\n";
    }
    
    // Проверяем имена общих файлов
    if (!empty($commonFiles)) {
        echo "\n--- Сравнение имён общих файлов ---\n";
        foreach ($commonFiles as $fileId) {
            $taskFile = null;
            foreach ($taskFiles as $file) {
                if (($file['id'] ?? $file['ID'] ?? null) == $fileId) {
                    $taskFile = $file;
                    break;
                }
            }
            
            $dealFile = null;
            foreach ($dealFiles as $file) {
                $fid = null;
                if (is_array($file)) {
                    $fid = $file['id'] ?? $file['ID'] ?? null;
                } elseif (is_scalar($file)) {
                    $fid = (string) $file;
                }
                if ($fid == $fileId) {
                    $dealFile = $file;
                    break;
                }
            }
            
            $diskInfo = getDiskFileInfo((string) $fileId);
            $finalName = null;
            if ($diskInfo !== null) {
                $finalName = $diskInfo['ORIGINAL_NAME']
                    ?? $diskInfo['originalName']
                    ?? $diskInfo['NAME']
                    ?? $diskInfo['name']
                    ?? null;
            }
            
            echo "Файл ID: {$fileId}\n";
            echo "  Имя в задаче: " . ($taskFile['name'] ?? $taskFile['NAME'] ?? 'null') . "\n";
            echo "  Имя в сделке: " . (is_array($dealFile) ? ($dealFile['name'] ?? $dealFile['NAME'] ?? 'null') : 'только ID') . "\n";
            echo "  Имя из disk.file.get: " . ($finalName ?? 'null') . "\n";
            
            if ($finalName !== null) {
                $ext = pathinfo($finalName, PATHINFO_EXTENSION);
                $mimeType = $diskInfo['CONTENT_TYPE'] ?? $diskInfo['contentType'] ?? $diskInfo['TYPE'] ?? $diskInfo['type'] ?? null;
                if (in_array(strtolower($ext), ['php', 'html', 'htm'], true) && str_starts_with(strtolower($mimeType ?? ''), 'image/')) {
                    echo "  ⚠️ ПРОБЛЕМА: Расширение .{$ext}, но MIME-тип {$mimeType} (изображение)\n";
                }
            }
            echo "\n";
        }
    }
}

echo "\n=== Проверка завершена ===\n";
