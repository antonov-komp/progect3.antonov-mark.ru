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

    public function buildDealFileData(string $fileId, callable $restCall): ?array
    {
        $info = $this->taskFiles->getDiskFileInfo($fileId, $restCall);
        if ($info === null) {
            return null;
        }

        $name = $info['name'] ?? ('file_' . $fileId);
        $downloadUrl = $info['downloadUrl'] ?? $info['DOWNLOAD_URL'] ?? null;
        if (!is_string($downloadUrl) || $downloadUrl === '') {
            return null;
        }

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
        // Если есть ID файла, используем REST API для получения файла (как для новых файлов)
        // Это предотвращает искажение данных при загрузке через HTTP
        $fileId = $entry['id'] ?? null;
        if ($fileId !== null && is_numeric($fileId)) {
            $info = $this->taskFiles->getDiskFileInfo((string) $fileId, $restCall);
            if ($info !== null) {
                $name = $info['name'] ?? ('file_' . $fileId);
                $downloadUrl = $info['downloadUrl'] ?? $info['DOWNLOAD_URL'] ?? null;
                if (is_string($downloadUrl) && $downloadUrl !== '') {
                    $downloadUrl = $this->request->resolveAbsoluteUrl($downloadUrl);
                    $base64 = $this->filesystem->downloadBase64($downloadUrl);
                    if ($base64 !== null) {
                        return [$name, $base64];
                    }
                }
            }
        }

        // Fallback: загрузка через downloadUrl (старый способ, может искажать данные)
        $downloadUrl = $entry['downloadUrl'] ?? null;
        if (!is_string($downloadUrl) || $downloadUrl === '') {
            return null;
        }

        $downloadUrl = $this->request->resolveAbsoluteUrl($downloadUrl);
        $base64 = $this->filesystem->downloadBase64($downloadUrl);
        if ($base64 === null) {
            return null;
        }

        $name = null;
        $path = parse_url($downloadUrl, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $basename = basename($path);
            if ($basename !== '') {
                $name = $basename;
            }
        }

        if ($name === null || $name === '') {
            $id = $entry['id'] ?? 'file';
            $name = 'deal_file_' . $id;
        }

        return [$name, $base64];
    }

    public function updateDealFiles(string $dealId, string $field, array $fileDataList, callable $restCall): array
    {
        $existingIds = $this->getDealFileField($dealId, $field, $restCall);
        $payload = [];
        $errors = [];

        foreach ($existingIds as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fileData = $this->buildFromDealEntry($entry, $restCall);
            if ($fileData === null) {
                $errors[] = ['fileId' => $entry['id'] ?? 'unknown', 'error' => 'failed_to_load_existing_file'];
                continue;
            }
            $payload[] = ['fileData' => $fileData];
        }

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
