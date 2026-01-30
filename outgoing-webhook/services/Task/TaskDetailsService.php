<?php
declare(strict_types=1);

class TaskDetailsService
{
    private FilesystemService $filesystem;
    private RequestService $request;
    private LogValueFormatter $formatter;
    private ?TaskDetailsRepository $taskDetailsRepository;
    private string $basePath;

    public function __construct(
        FilesystemService $filesystem, 
        RequestService $request, 
        LogValueFormatter $formatter,
        ?TaskDetailsRepository $taskDetailsRepository = null
    ) {
        $this->filesystem = $filesystem;
        $this->request = $request;
        $this->formatter = $formatter;
        $this->taskDetailsRepository = $taskDetailsRepository;
        $this->basePath = dirname(__DIR__, 2);
    }

    public function writeDetails(string $eventType, array $enriched, ?string $requestId, ?string $entityId): ?array
    {
        $taskData = $this->extractTaskData($enriched);
        if (!is_array($taskData)) {
            return null;
        }

        $details = $this->buildDetails($taskData, $eventType, $requestId, $entityId);
        $this->writeDetailsRu($eventType, $details);

        return $taskData;
    }

    public function extractTaskData(array $enriched): ?array
    {
        $taskData = $enriched['data']['task'] ?? null;
        if (!is_array($taskData)) {
            return null;
        }

        if (isset($taskData['task']) && is_array($taskData['task'])) {
            return $taskData['task'];
        }

        return $taskData;
    }

    public function buildDetails(
        array $taskData,
        string $eventType,
        ?string $requestId,
        ?string $entityId
    ): array {
        return [
            'loggedAt' => $this->request->now(),
            'requestId' => $requestId ?? 'unknown',
            'eventType' => $eventType,
            'taskId' => $entityId
                ?? $this->request->getFirstValue($taskData, ['ID', 'id'])
                ?? 'unknown',
            'title' => $this->request->getFirstValue($taskData, ['TITLE', 'NAME', 'title']) ?? 'unknown',
            'createdBy' => $this->request->getFirstValue(
                $taskData,
                ['CREATED_BY', 'CREATED_BY_ID', 'createdBy', ['creator', 'id']]
            ) ?? 'unknown',
            'groupId' => $this->request->getFirstValue(
                $taskData,
                ['GROUP_ID', 'PROJECT_ID', 'groupId', ['group', 'id']]
            ) ?? 'unknown',
            'deadline' => $this->request->getFirstValue($taskData, ['DEADLINE', 'deadline']) ?? 'unknown',
            'startDatePlan' => $this->request->getFirstValue($taskData, ['START_DATE_PLAN', 'startDatePlan']) ?? 'unknown',
            'endDatePlan' => $this->request->getFirstValue($taskData, ['END_DATE_PLAN', 'endDatePlan']) ?? 'unknown',
        ];
    }

    public function formatDetailsRu(array $details): string
    {
        return sprintf(
            'Дата=%s | requestId=%s | Событие=%s | Задача=%s | Название=%s | Постановщик=%s | Проект=%s | Срок=%s | ПланСтарт=%s | ПланФиниш=%s',
            $details['loggedAt'] ?? 'unknown',
            $details['requestId'] ?? 'unknown',
            $details['eventType'] ?? 'unknown',
            $details['taskId'] ?? 'unknown',
            $this->formatter->normalize($details['title'] ?? 'unknown'),
            $details['createdBy'] ?? 'unknown',
            $details['groupId'] ?? 'unknown',
            $details['deadline'] ?? 'unknown',
            $details['startDatePlan'] ?? 'unknown',
            $details['endDatePlan'] ?? 'unknown'
        );
    }

    public function writeDetailsRu(string $eventType, array $details): void
    {
        if (!str_starts_with($eventType, 'ONTASK')) {
            return;
        }

        // Запись в файл (для обратной совместимости)
        $eventDir = $this->basePath . '/logs/' . $eventType;
        $this->filesystem->ensureDir($eventDir);
        $formattedDetails = $this->formatDetailsRu($details);
        $this->filesystem->appendLine($eventDir . '/task-details.log', $formattedDetails);

        // Запись в БД (если репозиторий доступен)
        if ($this->taskDetailsRepository !== null) {
            $taskId = $details['taskId'] ?? $details['id'] ?? null;
            $requestId = $details['requestId'] ?? null;
            
            if ($taskId !== null && $requestId !== null) {
                $detailsData = [
                    'requestId' => $requestId,
                    'eventType' => $eventType,
                    'taskId' => (string) $taskId,
                    'details' => $details,
                    'formattedDetails' => $formattedDetails,
                    'createdAt' => $this->request->now(),
                ];
                
                $id = $this->taskDetailsRepository->create($detailsData);
                if ($id === null) {
                    // Ошибка уже залогирована в репозитории
                }
            }
        }
    }

    public function extractMeta(?array $taskData): array
    {
        if (!is_array($taskData)) {
            return [
                'projectId' => 'unknown',
                'projectName' => 'unknown',
                'crmLinks' => [],
            ];
        }

        $projectId = $this->request->getFirstValue($taskData, ['GROUP_ID', 'groupId', ['group', 'id']]) ?? 'unknown';
        $projectName = $this->request->getFirstValue($taskData, ['GROUP_NAME', ['group', 'name']]) ?? 'unknown';

        $crmLinks = [];
        if (isset($taskData['UF_CRM_TASK']) && is_array($taskData['UF_CRM_TASK'])) {
            foreach ($taskData['UF_CRM_TASK'] as $link) {
                if ($link !== null && $link !== '') {
                    $crmLinks[] = (string) $link;
                }
            }
        }
        $crmLinks = array_values(array_unique($crmLinks));

        return [
            'projectId' => (string) $projectId,
            'projectName' => (string) $projectName,
            'crmLinks' => $crmLinks,
        ];
    }

    /**
     * @deprecated Use loadActivityConfig() instead
     * Загрузка условий ActivityFirst (старый формат)
     */
    public function loadActivityFirstConditions(): array
    {
        $path = $this->basePath . '/activity/first/conditions.php';
        if (file_exists($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                return $loaded;
            }
        }

        return [
            'projectId' => null,
            'crmDealPrefix' => 'D_',
            'keywords' => [],
        ];
    }

    /**
     * Загрузка конфигурации Activity (новый формат)
     * 
     * @return ActivityConfigService Сервис конфигурации Activity
     */
    public function loadActivityConfig(): ActivityConfigService
    {
        $configPath = $this->basePath . '/activity/config.php';
        return new ActivityConfigService($configPath);
    }

    public function messageHasKeyword(string $message, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || $keyword === '') {
                continue;
            }
            if (function_exists('mb_stripos')) {
                if (mb_stripos($message, $keyword) !== false) {
                    return true;
                }
            } else {
                if (stripos($message, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasDealLink(array $crmLinks, string $dealPrefix): bool
    {
        foreach ($crmLinks as $link) {
            if (!is_string($link)) {
                continue;
            }
            if ($dealPrefix !== '' && str_starts_with($link, $dealPrefix)) {
                return true;
            }
            if (stripos($link, '/crm/deal/') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @deprecated Use evaluateActivity() instead
     * Оценка условий ActivityFirst (старый формат)
     */
    public function evaluateActivityFirst(array $details): bool
    {
        $activityType = $this->evaluateActivity($details);
        return $activityType !== null;
    }

    /**
     * Оценка условий Activity и определение типа Activity
     *
     * Учитываются только контекст и содержимое: задача, проект, сделка, текст, файл.
     * Кто именно написал комментарий (authorId) не учитывается — только анализ условий.
     *
     * Алгоритм:
     * 1. Загрузить конфигурацию через ActivityConfigService
     * 2. Для каждого типа Activity:
     *    - Проверить проект (если задан)
     *    - Проверить CRM-связь со сделкой
     *    - Проверить наличие файлов
     *    - Проверить ключевые слова в сообщении (с учетом опечаток)
     * 3. Вернуть первый подходящий тип Activity или null
     *
     * @param array $details Детали комментария
     * @return string|null Тип Activity ('cover', 'approved_form') или null если условия не выполнены
     */
    public function evaluateActivity(array $details): ?string
    {
        $configService = $this->loadActivityConfig();
        $commonConfig = $configService->getCommonConfig();
        
        $projectId = (string) ($commonConfig['projectId'] ?? '');
        $dealPrefix = (string) ($commonConfig['crmDealPrefix'] ?? 'D_');
        
        // Проверка проекта
        if ($projectId !== '' && ($details['projectId'] ?? '') !== $projectId) {
            return null;
        }
        
        // Проверка CRM-связи
        $crmLinks = is_array($details['crmLinks'] ?? null) ? $details['crmLinks'] : [];
        if (!$this->hasDealLink($crmLinks, $dealPrefix)) {
            return null;
        }
        
        // Проверка файлов
        $fileIds = $details['fileIds'] ?? [];
        if (!is_array($fileIds) || empty($fileIds)) {
            return null;
        }
        
        // Проверка сообщения
        $message = (string) ($details['message'] ?? '');
        if ($message === '') {
            return null;
        }
        
        // Проверка каждого типа Activity
        foreach ($configService->getActivityTypes() as $activityType) {
            if ($configService->messageHasActivityKeyword($message, $activityType)) {
                return $activityType;
            }
        }
        
        return null;
    }

    /**
     * Получение поля сделки для типа Activity
     * 
     * @param string $activityType Тип Activity ('cover', 'approved_form')
     * @return string Поле сделки или пустая строка если не найдено
     */
    public function getActivityDealField(string $activityType): string
    {
        $configService = $this->loadActivityConfig();
        return $configService->getDealField($activityType);
    }

    /**
     * Проверка наличия ключевого слова типа Activity в сообщении
     * 
     * @param string $message Сообщение для проверки
     * @param string $activityType Тип Activity
     * @return bool true если найдено ключевое слово
     */
    public function messageHasActivityKeyword(string $message, string $activityType): bool
    {
        $configService = $this->loadActivityConfig();
        return $configService->messageHasActivityKeyword($message, $activityType);
    }

    public function loadCrmLinks(string $taskId, callable $restCall): array
    {
        $result = $restCall('task.item.getdata', ['TASKID' => (int) $taskId]);
        if (!is_array($result) || !empty($result['error'])) {
            return [];
        }

        $data = $result['result'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        $links = $data['UF_CRM_TASK'] ?? [];
        if (!is_array($links)) {
            return [];
        }

        $normalized = [];
        foreach ($links as $link) {
            if ($link !== null && $link !== '') {
                $normalized[] = (string) $link;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function ensureCrmLinks(?array $taskData, string $taskId, callable $restCall): ?array
    {
        if (!is_array($taskData)) {
            return $taskData;
        }

        if (isset($taskData['UF_CRM_TASK']) && is_array($taskData['UF_CRM_TASK'])) {
            return $taskData;
        }

        $links = $this->loadCrmLinks($taskId, $restCall);
        if (!empty($links)) {
            $taskData['UF_CRM_TASK'] = $links;
        }

        return $taskData;
    }

    public function extractDealIds(array $crmLinks): array
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

    public function logActivityFirst(array $entry): void
    {
        $path = $this->basePath . '/logs/activity-first.log';
        $this->filesystem->appendLine($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Маркировка события как синхронно обработанного
     * 
     * @param string $requestId ID запроса
     * @param string $taskId ID задачи
     */
    public function markActivityFirstProcessed(string $requestId, string $taskId): void
    {
        $stateDir = $this->basePath . '/state/activity-first-processed';
        $this->filesystem->ensureDir($stateDir);
        
        $stateFile = $stateDir . '/' . $requestId . '_' . $taskId . '.json';
        $state = [
            'requestId' => $requestId,
            'taskId' => $taskId,
            'processedAt' => $this->request->now(),
            'sync' => true,
        ];
        
        $this->filesystem->writeJson($stateFile, $state);
    }

    /**
     * Проверка, было ли событие уже обработано синхронно
     * 
     * @param string $requestId ID запроса
     * @param string $taskId ID задачи
     * @return bool
     */
    public function isActivityFirstProcessed(string $requestId, string $taskId): bool
    {
        $stateFile = $this->basePath . '/state/activity-first-processed/' . $requestId . '_' . $taskId . '.json';
        return file_exists($stateFile);
    }
}
