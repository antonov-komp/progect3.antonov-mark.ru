<?php
declare(strict_types=1);

/**
 * Обработчик ActivityFirst
 * 
 * Ответственность:
 * - Обработка ActivityFirst (синхронная или асинхронная)
 */
class ActivityFirstProcessor
{
    private TaskDetailsService $taskDetails;
    private TaskFilesService $taskFiles;
    private DealFileService $dealFiles;

    public function __construct(
        TaskDetailsService $taskDetails,
        TaskFilesService $taskFiles,
        DealFileService $dealFiles
    ) {
        $this->taskDetails = $taskDetails;
        $this->taskFiles = $taskFiles;
        $this->dealFiles = $dealFiles;
    }

    /**
     * Обработка ActivityFirst
     * 
     * @param array $commentDetails Детали комментария с ActivityFirst=да
     * @param string $entityId ID задачи
     * @param callable $restCall Функция для REST API вызовов
     * @return array Результат обработки для логирования
     * @throws InvalidArgumentException При отсутствии необходимых данных
     */
    public function process(
        array $commentDetails,
        string $entityId,
        callable $restCall
    ): array {
        // Валидация входных данных
        if (empty($entityId) || !is_string($entityId)) {
            throw new InvalidArgumentException('Invalid taskId: ' . ($entityId ?? 'null'));
        }

        $fileIds = $commentDetails['fileIds'] ?? [];
        $fileIds = is_array($fileIds) ? array_values(array_unique($fileIds)) : [];
        
        if (empty($fileIds)) {
            throw new InvalidArgumentException('FileIds are required for ActivityFirst processing');
        }

        $dealIds = $this->taskDetails->extractDealIds($commentDetails['crmLinks'] ?? []);
        
        if (empty($dealIds)) {
            throw new InvalidArgumentException('DealIds are required for ActivityFirst processing');
        }

        // Валидация каждого fileId
        foreach ($fileIds as $fileId) {
            if (!is_numeric($fileId) || (int)$fileId <= 0) {
                throw new InvalidArgumentException('Invalid fileId: ' . $fileId);
            }
        }

        // Валидация каждого dealId
        foreach ($dealIds as $dealId) {
            if (empty($dealId) || !is_string($dealId)) {
                throw new InvalidArgumentException('Invalid dealId: ' . ($dealId ?? 'null'));
            }
        }

        $taskAttach = ['attached' => [], 'errors' => []];
        if (!empty($fileIds)) {
            $taskAttach = $this->taskFiles->attachFiles($entityId, $fileIds, $restCall);
        }

        $dealUpdates = [];
        $fileDataList = [];
        foreach ($fileIds as $fileId) {
            $fileData = $this->dealFiles->buildDealFileData($fileId, $restCall);
            if ($fileData !== null) {
                $fileDataList[] = ['fileData' => $fileData];
            }
        }

        foreach ($dealIds as $dealId) {
            $dealUpdates[] = array_merge(
                ['dealId' => $dealId],
                $this->dealFiles->updateDealFiles($dealId, 'UF_CRM_1759233362672', $fileDataList, $restCall)
            );
        }

        return [
            'dealIds' => $dealIds,
            'fileIds' => $fileIds,
            'taskAttach' => $taskAttach,
            'dealUpdates' => $dealUpdates,
            'author_id' => (string) ($commentDetails['authorId'] ?? ''),
            'comment_text' => (string) ($commentDetails['message'] ?? ''),
        ];
    }
}
