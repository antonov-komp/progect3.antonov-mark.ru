<?php
declare(strict_types=1);

$logsRoot = realpath(__DIR__ . '/../../outgoing-webhook/logs');
if ($logsRoot === false || !is_dir($logsRoot)) {
    fwrite(STDERR, "Logs directory not found.\n");
    exit(1);
}

$deletedFiles = 0;
$deletedDirs = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($logsRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $item) {
    $path = $item->getPathname();
    if ($item->isFile()) {
        if (@unlink($path)) {
            $deletedFiles++;
        }
        continue;
    }

    if ($item->isDir()) {
        $files = scandir($path);
        if ($files !== false && count($files) === 2) {
            if (@rmdir($path)) {
                $deletedDirs++;
            }
        }
    }
}

echo "Outgoing webhook logs cleared.\n";
echo "Deleted files: {$deletedFiles}\n";
echo "Deleted empty directories: {$deletedDirs}\n";
