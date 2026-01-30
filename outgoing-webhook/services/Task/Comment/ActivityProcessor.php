<?php
declare(strict_types=1);

/**
 * Обработчик Activity
 *
 * Ответственность:
 * - Обработка Activity (синхронная или асинхронная)
 * - Поддержка нескольких типов Activity с разными полями сделок
 *
 * Хранилища файлов (Bitrix24):
 * - Файл в чате задачи: пользователь прикрепил файл к комментарию (скрепка/драг-н-дроп).
 *   Событие приходит с fileId — это ID файла на диске (загрузка с нуля, ID диска).
 *   Такой ID можно использовать для присоединения к задаче (tasks.task.files.attach).
 * - Поле файла в сделке (UF_CRM_*): другое хранилище. В сделку ID файла из задачи/чата
 *   «прокинуть» нельзя — файлы для сделки и файлы в задачах это разные хранилища.
 *   Для сделки получаем контент файла (disk.file.get → downloadUrl → base64) и
 *   передаём в crm.deal.update в формате fileData ([имя, base64]).
 */
class ActivityProcessor
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
     * Обработка Activity
     * 
     * @param array $commentDetails Детали комментария с activityType
     * @param string $activityType Тип Activity ('cover', 'approved_form')
     * @param string $entityId ID задачи
     * @param callable $restCall Функция для REST API вызовов
     * @return array Результат обработки для логирования
     * @throws InvalidArgumentException При отсутствии необходимых данных
     */
    public function process(
        array $commentDetails,
        string $activityType,
        string $entityId,
        callable $restCall
    ): array {
        // Валидация входных данных
        if (empty($entityId) || !is_string($entityId)) {
            throw new InvalidArgumentException('Invalid taskId: ' . ($entityId ?? 'null'));
        }

        if (empty($activityType) || !is_string($activityType)) {
            throw new InvalidArgumentException('Invalid activityType: ' . ($activityType ?? 'null'));
        }

        $fileIds = $commentDetails['fileIds'] ?? [];
        $fileIds = is_array($fileIds) ? array_values(array_unique($fileIds)) : [];
        
        if (empty($fileIds)) {
            throw new InvalidArgumentException('FileIds are required for Activity processing');
        }

        $dealIds = $this->taskDetails->extractDealIds($commentDetails['crmLinks'] ?? []);
        
        if (empty($dealIds)) {
            throw new InvalidArgumentException('DealIds are required for Activity processing');
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

        // Получение поля сделки для типа Activity
        $dealField = $this->taskDetails->getActivityDealField($activityType);
        if ($dealField === '') {
            throw new InvalidArgumentException('Invalid activity type or deal field: ' . $activityType);
        }

        // Прикрепление файлов к задаче (fileId из чата = ID диска, подходит для задачи)
        $taskAttach = ['attached' => [], 'errors' => []];
        if (!empty($fileIds)) {
            $taskAttach = $this->taskFiles->attachFiles($entityId, $fileIds, $restCall);
        }

        // Построение данных файлов для сделки: ID в сделку не подставляем — хранилище другое.
        // Получаем контент (disk.file.get → downloadUrl → base64) и передаём fileData в crm.deal.update.
        $fileDataList = [];
        foreach ($fileIds as $fileId) {
            $fileData = $this->dealFiles->buildDealFileData($fileId, $restCall);
            if ($fileData !== null) {
                $fileDataList[] = ['fileData' => $fileData];
            }
        }

        // Обновление файлов в сделках (с правильным полем для типа Activity)
        $dealUpdates = [];
        foreach ($dealIds as $dealId) {
            $dealUpdates[] = array_merge(
                ['dealId' => $dealId],
                $this->dealFiles->updateDealFiles($dealId, $dealField, $fileDataList, $restCall)
            );
        }

        return [
            'activityType' => $activityType,
            'dealField' => $dealField,
            'dealIds' => $dealIds,
            'fileIds' => $fileIds,
            'taskAttach' => $taskAttach,
            'dealUpdates' => $dealUpdates,
        ];
    }
}
