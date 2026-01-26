<?php
declare(strict_types=1);

class FilesystemService
{
    public function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }

    public function writeJson(string $path, array $data): bool
    {
        $this->ensureDir(dirname($path));

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = json_encode(['error' => 'json_encode_failed']);
        }

        return file_put_contents($path, $json . PHP_EOL) !== false;
    }

    public function appendLine(string $path, string $line): bool
    {
        $this->ensureDir(dirname($path));

        // Используем fopen с блокировкой для гарантии записи на диск
        // Особенно важно после fastcgi_finish_request()
        $handle = @fopen($path, 'a');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        $result = fwrite($handle, $line . PHP_EOL);
        fflush($handle); // Явная синхронизация
        flock($handle, LOCK_UN);
        fclose($handle);

        return $result !== false;
    }

    public function downloadBase64(string $url): ?string
    {
        $data = @file_get_contents($url);
        if ($data === false) {
            return null;
        }

        return base64_encode($data);
    }
}
