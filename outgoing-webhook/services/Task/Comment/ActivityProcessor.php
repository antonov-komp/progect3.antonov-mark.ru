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
    private ConfigService $config;

    public function __construct(
        TaskDetailsService $taskDetails,
        TaskFilesService $taskFiles,
        DealFileService $dealFiles,
        ConfigService $config
    ) {
        $this->taskDetails = $taskDetails;
        $this->taskFiles = $taskFiles;
        $this->dealFiles = $dealFiles;
        $this->config = $config;
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
        callable $restCall,
        array $context = []
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

        // Формируем контекст для логирования
        $context = [
            'taskId' => $entityId,
            'activityType' => $activityType,
            'dealField' => $dealField,
            'dealIds' => $dealIds,
            'fileIds' => $fileIds,
        ];

        // Построение данных файлов для сделки: ID в сделку не подставляем — хранилище другое.
        // Получаем контент (disk.file.get → downloadUrl → base64) и передаём fileData в crm.deal.update.
        // Используем имя файла из buildDealFileData(), так как оно правильно обрабатывает расширения через resolveFileName()
        $fileDataList = [];
        $file_name = '';
        $file_size = null;
        $firstFileId = $fileIds[0] ?? null;
        $processedFiles = [];
        
        foreach ($fileIds as $index => $fileId) {
            $fileData = $this->dealFiles->buildDealFileData($fileId, $restCall, array_merge($context, [
                'fileIndex' => $index,
            ]));
            if ($fileData !== null) {
                $fileDataList[] = ['fileData' => $fileData];
                $processedFiles[] = [
                    'fileId' => $fileId,
                    'fileName' => $fileData[0] ?? null,
                    'hasExtension' => !empty(pathinfo($fileData[0] ?? '', PATHINFO_EXTENSION)),
                ];
                
                // Для первого файла сохраняем имя и размер для метрик
                // Имя берём из buildDealFileData(), так как оно правильно обработано через resolveFileName()
                if ($fileId === $firstFileId) {
                    $file_name = $fileData[0] ?? ''; // Имя файла из buildDealFileData()
                    
                    // Размер получаем из disk.file.get
                    $fileInfo = $this->taskFiles->getDiskFileInfo((string) $fileId, $restCall);
                    if (is_array($fileInfo)) {
                        $size = $fileInfo['SIZE'] ?? $fileInfo['size'] ?? null;
                        $file_size = is_numeric($size) ? (int) $size : null;
                    }
                }
            }
        }

        // Обновление файлов в сделках (с правильным полем для типа Activity)
        $entityTypeId = $this->resolveEntityTypeId();
        $dealUpdates = [];
        foreach ($dealIds as $dealId) {
            $dealUpdateResult = $this->dealFiles->updateDealFiles(
                $dealId, 
                $dealField, 
                $fileDataList, 
                $restCall, 
                $entityTypeId,
                array_merge($context, ['dealId' => $dealId])
            );
            $dealUpdates[] = array_merge(
                ['dealId' => $dealId],
                $dealUpdateResult
            );
        }

        // Проверка успеха по факту наличия файлов
        $taskFilesAfter = $this->taskFiles->getAttachedFiles($entityId, $restCall);
        $verified_task_files_count = count($taskFilesAfter);
        $verified_deal_files_count = 0;
        if (!empty($dealIds)) {
            $firstDealFiles = $this->dealFiles->getDealFileField($dealIds[0], $dealField, $restCall, $entityTypeId);
            $verified_deal_files_count = count($firstDealFiles);
        }

        // Повторная попытка перезакачки/прикрепления при ненасыщенном результате (ERROR_CORE и т.п.)
        $retryDelaySeconds = 2;
        $needRetry = ($verified_task_files_count === 0 && !empty($taskAttach['errors']))
            || ($verified_deal_files_count === 0 && !empty($fileIds));
        $retried = false;
        if ($needRetry) {
            $retried = true;
            if (function_exists('usleep') && $retryDelaySeconds > 0) {
                usleep($retryDelaySeconds * 1000000);
            }
            // Повтор: прикрепление к задаче
            $taskAttachRetry = $this->taskFiles->attachFiles($entityId, $fileIds, $restCall);
            if (!empty($taskAttachRetry['attached'])) {
                $taskAttach['attached'] = array_values(array_unique(array_merge($taskAttach['attached'], $taskAttachRetry['attached'])));
            }
            if (!empty($taskAttachRetry['errors'])) {
                $taskAttach['errors'] = array_merge($taskAttach['errors'], $taskAttachRetry['errors']);
            }
            // Повтор: пересборка fileData и обновление сделок (перезакачка через disk.file.get)
            $fileDataListRetry = [];
            foreach ($fileIds as $index => $fileId) {
                $fileData = $this->dealFiles->buildDealFileData($fileId, $restCall, array_merge($context, [
                    'fileIndex' => $index,
                    'retry' => true,
                ]));
                if ($fileData !== null) {
                    $fileDataListRetry[] = ['fileData' => $fileData];
                }
            }
            if (!empty($fileDataListRetry)) {
                $dealUpdates = [];
                foreach ($dealIds as $dealId) {
                    $dealUpdateResult = $this->dealFiles->updateDealFiles(
                        $dealId, 
                        $dealField, 
                        $fileDataListRetry, 
                        $restCall, 
                        $entityTypeId,
                        array_merge($context, ['dealId' => $dealId, 'retry' => true])
                    );
                    $dealUpdates[] = array_merge(
                        ['dealId' => $dealId],
                        $dealUpdateResult
                    );
                }
            }
            // Повторная верификация
            $taskFilesAfter = $this->taskFiles->getAttachedFiles($entityId, $restCall);
            $verified_task_files_count = count($taskFilesAfter);
            $verified_deal_files_count = 0;
            if (!empty($dealIds)) {
                $firstDealFiles = $this->dealFiles->getDealFileField($dealIds[0], $dealField, $restCall, $entityTypeId);
                $verified_deal_files_count = count($firstDealFiles);
            }
        }

        return [
            'activityType' => $activityType,
            'dealField' => $dealField,
            'dealIds' => $dealIds,
            'fileIds' => $fileIds,
            'taskAttach' => $taskAttach,
            'dealUpdates' => $dealUpdates,
            'file_name' => $file_name,
            'file_size' => $file_size,
            'author_id' => (string) ($commentDetails['authorId'] ?? ''),
            'comment_text' => (string) ($commentDetails['message'] ?? ''),
            'retried' => $retried,
            'verified' => [
                'task_files_count' => $verified_task_files_count,
                'deal_files_count' => $verified_deal_files_count,
            ],
        ];
    }

    private function resolveEntityTypeId(): int
    {
        $value = (int) ($this->config->get('ACTIVITY_ENTITY_TYPE_ID', '2') ?? 2);
        return $value > 0 ? $value : 2;
    }
}
