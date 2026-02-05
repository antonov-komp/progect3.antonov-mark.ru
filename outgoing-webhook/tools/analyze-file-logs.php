<?php
declare(strict_types=1);

/**
 * Утилита для анализа логов обработки файлов в сделках
 * 
 * Использование:
 * php tools/analyze-file-logs.php [опции]
 * 
 * Опции:
 * --file-id=ID          Фильтр по fileId
 * --deal-id=ID          Фильтр по dealId
 * --task-id=ID          Фильтр по taskId
 * --request-id=ID        Фильтр по requestId
 * --activity-type=TYPE  Фильтр по типу Activity
 * --no-extension        Показать только файлы без расширения
 * --date=YYYY-MM-DD     Фильтр по дате (по умолчанию сегодня)
 * --method=METHOD       Фильтр по методу (buildDealFileData, buildFromDealEntry, updateDealFiles)
 * --level=LEVEL         Фильтр по уровню (debug, info, warning, error)
 * --summary             Показать сводку статистики
 */

require_once __DIR__ . '/../bootstrap.php';

$logDir = __DIR__ . '/../logs/file-processing';
$date = $argv[1] ?? date('Y-m-d');
$filters = [
    'fileId' => null,
    'dealId' => null,
    'taskId' => null,
    'requestId' => null,
    'activityType' => null,
    'method' => null,
    'level' => null,
    'noExtension' => false,
    'summary' => false,
];

// Парсинг аргументов командной строки
foreach ($argv as $arg) {
    if (strpos($arg, '--file-id=') === 0) {
        $filters['fileId'] = substr($arg, 10);
    } elseif (strpos($arg, '--deal-id=') === 0) {
        $filters['dealId'] = substr($arg, 10);
    } elseif (strpos($arg, '--task-id=') === 0) {
        $filters['taskId'] = substr($arg, 10);
    } elseif (strpos($arg, '--request-id=') === 0) {
        $filters['requestId'] = substr($arg, 13);
    } elseif (strpos($arg, '--activity-type=') === 0) {
        $filters['activityType'] = substr($arg, 16);
    } elseif (strpos($arg, '--method=') === 0) {
        $filters['method'] = substr($arg, 9);
    } elseif (strpos($arg, '--level=') === 0) {
        $filters['level'] = substr($arg, 8);
    } elseif ($arg === '--no-extension') {
        $filters['noExtension'] = true;
    } elseif ($arg === '--summary') {
        $filters['summary'] = true;
    } elseif (strpos($arg, '--date=') === 0) {
        $date = substr($arg, 7);
    }
}

$logFile = $logDir . '/file-processing-' . str_replace('-', '', $date) . '.log';

if (!file_exists($logFile)) {
    echo "Лог-файл не найден: {$logFile}\n";
    exit(1);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$entries = [];
$stats = [
    'total' => 0,
    'byLevel' => [],
    'byMethod' => [],
    'withoutExtension' => 0,
    'withExtension' => 0,
    'extensionSources' => [],
];

foreach ($lines as $line) {
    $entry = json_decode($line, true);
    if (!is_array($entry)) {
        continue;
    }
    
    $stats['total']++;
    
    // Применяем фильтры
    $matches = true;
    
    if ($filters['fileId'] !== null) {
        $fileId = $entry['context']['fileId'] ?? $entry['context']['entryId'] ?? null;
        if ($fileId !== $filters['fileId']) {
            $matches = false;
        }
    }
    
    if ($matches && $filters['dealId'] !== null) {
        $dealId = $entry['context']['dealId'] ?? null;
        if ($dealId !== $filters['dealId']) {
            $matches = false;
        }
    }
    
    if ($matches && $filters['taskId'] !== null) {
        $taskId = $entry['context']['taskId'] ?? null;
        if ($taskId !== $filters['taskId']) {
            $matches = false;
        }
    }
    
    if ($matches && $filters['requestId'] !== null) {
        $requestId = $entry['context']['requestId'] ?? null;
        if ($requestId !== $filters['requestId']) {
            $matches = false;
        }
    }
    
    if ($matches && $filters['activityType'] !== null) {
        $activityType = $entry['context']['activityType'] ?? null;
        if ($activityType !== $filters['activityType']) {
            $matches = false;
        }
    }
    
    if ($matches && $filters['method'] !== null) {
        if ($entry['method'] !== $filters['method']) {
            $matches = false;
        }
    }
    
    if ($matches && $filters['level'] !== null) {
        if ($entry['level'] !== $filters['level']) {
            $matches = false;
        }
    }
    
    if ($matches && $filters['noExtension']) {
        $hasExtension = $entry['result']['hasExtension'] ?? true;
        if ($hasExtension) {
            $matches = false;
        }
    }
    
    if ($matches) {
        $entries[] = $entry;
        
        // Собираем статистику
        $level = $entry['level'] ?? 'unknown';
        $stats['byLevel'][$level] = ($stats['byLevel'][$level] ?? 0) + 1;
        
        $method = $entry['method'] ?? 'unknown';
        $stats['byMethod'][$method] = ($stats['byMethod'][$method] ?? 0) + 1;
        
        $hasExtension = $entry['result']['hasExtension'] ?? true;
        if ($hasExtension) {
            $stats['withExtension']++;
        } else {
            $stats['withoutExtension']++;
        }
        
        $extensionSource = $entry['result']['extensionSource'] ?? null;
        if ($extensionSource !== null) {
            $stats['extensionSources'][$extensionSource] = ($stats['extensionSources'][$extensionSource] ?? 0) + 1;
        }
    }
}

if ($filters['summary']) {
    echo "=== Сводка статистики ===\n\n";
    echo "Всего записей: {$stats['total']}\n";
    echo "С расширением: {$stats['withExtension']}\n";
    echo "Без расширения: {$stats['withoutExtension']}\n\n";
    
    echo "По уровням:\n";
    foreach ($stats['byLevel'] as $level => $count) {
        echo "  {$level}: {$count}\n";
    }
    echo "\n";
    
    echo "По методам:\n";
    foreach ($stats['byMethod'] as $method => $count) {
        echo "  {$method}: {$count}\n";
    }
    echo "\n";
    
    echo "Источники расширений:\n";
    foreach ($stats['extensionSources'] as $source => $count) {
        echo "  {$source}: {$count}\n";
    }
    echo "\n";
    
    if ($stats['withoutExtension'] > 0) {
        echo "⚠️  ВНИМАНИЕ: Найдено {$stats['withoutExtension']} файлов без расширения!\n";
    }
} else {
    echo "=== Анализ логов обработки файлов ===\n";
    echo "Дата: {$date}\n";
    echo "Всего записей: " . count($entries) . "\n\n";
    
    if (empty($entries)) {
        echo "Записи не найдены по заданным фильтрам.\n";
        exit(0);
    }
    
    // Группируем по fileId для удобства анализа
    $byFileId = [];
    foreach ($entries as $entry) {
        $fileId = $entry['context']['fileId'] ?? $entry['context']['entryId'] ?? 'unknown';
        if (!isset($byFileId[$fileId])) {
            $byFileId[$fileId] = [];
        }
        $byFileId[$fileId][] = $entry;
    }
    
    foreach ($byFileId as $fileId => $fileEntries) {
        echo "--- Файл ID: {$fileId} ---\n";
        
        foreach ($fileEntries as $entry) {
            $timestamp = $entry['timestamp'] ?? 'unknown';
            $level = $entry['level'] ?? 'unknown';
            $method = $entry['method'] ?? 'unknown';
            $step = $entry['data']['step'] ?? 'unknown';
            
            echo "[{$timestamp}] {$level} | {$method} | {$step}\n";
            
            // Показываем результат
            if (isset($entry['result'])) {
                $result = $entry['result'];
                $fileName = $result['fileName'] ?? 'unknown';
                $hasExtension = $result['hasExtension'] ?? true;
                $extension = $result['extension'] ?? null;
                $extensionSource = $result['extensionSource'] ?? null;
                
                echo "  Результат: {$fileName}\n";
                echo "  Расширение: " . ($hasExtension ? "✓ {$extension} (из {$extensionSource})" : "✗ отсутствует") . "\n";
                
                if (!$hasExtension) {
                    echo "  ⚠️  ПРОБЛЕМА: Файл без расширения!\n";
                }
            }
            
            // Показываем данные для диагностики
            if (isset($entry['data']['diskFileInfo'])) {
                $info = $entry['data']['diskFileInfo'];
                echo "  disk.file.get:\n";
                echo "    NAME: " . ($info['NAME'] ?? 'null') . "\n";
                echo "    ORIGINAL_NAME: " . ($info['ORIGINAL_NAME'] ?? 'null') . "\n";
                echo "    CONTENT_TYPE: " . ($info['CONTENT_TYPE'] ?? 'null') . "\n";
            }
            
            if (isset($entry['data']['mimeTypeFromMetadata'])) {
                echo "  MIME из метаданных: " . ($entry['data']['mimeTypeFromMetadata'] ?? 'null') . "\n";
            }
            
            if (isset($entry['data']['mimeTypeFromContent'])) {
                echo "  MIME из содержимого: " . ($entry['data']['mimeTypeFromContent'] ?? 'null') . "\n";
            }
            
            if (isset($entry['data']['extensionFromMime'])) {
                echo "  Расширение из MIME: " . ($entry['data']['extensionFromMime'] ?? 'null') . "\n";
            }
            
            echo "\n";
        }
    }
    
    // Показываем файлы без расширения
    $filesWithoutExtension = [];
    foreach ($entries as $entry) {
        $hasExtension = $entry['result']['hasExtension'] ?? true;
        if (!$hasExtension) {
            $fileId = $entry['context']['fileId'] ?? $entry['context']['entryId'] ?? 'unknown';
            $fileName = $entry['result']['fileName'] ?? 'unknown';
            $filesWithoutExtension[] = [
                'fileId' => $fileId,
                'fileName' => $fileName,
                'method' => $entry['method'] ?? 'unknown',
                'timestamp' => $entry['timestamp'] ?? 'unknown',
            ];
        }
    }
    
    if (!empty($filesWithoutExtension)) {
        echo "\n=== Файлы без расширения ===\n";
        foreach ($filesWithoutExtension as $file) {
            echo "  FileId: {$file['fileId']} | FileName: {$file['fileName']} | Method: {$file['method']} | Time: {$file['timestamp']}\n";
        }
    }
}
