<?php
declare(strict_types=1);

class TaskDetailsService
{
    private FilesystemService $filesystem;
    private RequestService $request;
    private LogValueFormatter $formatter;
    private string $basePath;

    public function __construct(FilesystemService $filesystem, RequestService $request, LogValueFormatter $formatter)
    {
        $this->filesystem = $filesystem;
        $this->request = $request;
        $this->formatter = $formatter;
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

        $eventDir = $this->basePath . '/logs/' . $eventType;
        $this->filesystem->ensureDir($eventDir);
        $this->filesystem->appendLine($eventDir . '/task-details.log', $this->formatDetailsRu($details));
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

    public function evaluateActivityFirst(array $details): bool
    {
        $conditions = $this->loadActivityFirstConditions();
        $projectId = (string) ($conditions['projectId'] ?? '');
        $dealPrefix = (string) ($conditions['crmDealPrefix'] ?? 'D_');
        $keywords = is_array($conditions['keywords'] ?? null) ? $conditions['keywords'] : [];

        if ($projectId !== '' && ($details['projectId'] ?? '') !== $projectId) {
            return false;
        }

        $crmLinks = is_array($details['crmLinks'] ?? null) ? $details['crmLinks'] : [];
        if (!$this->hasDealLink($crmLinks, $dealPrefix)) {
            return false;
        }

        $message = (string) ($details['message'] ?? '');
        if ($message === '') {
            return false;
        }

        if (!$this->messageHasKeyword($message, $keywords)) {
            return false;
        }

        $fileIds = $details['fileIds'] ?? [];
        if (!is_array($fileIds) || empty($fileIds)) {
            return false;
        }

        return true;
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
}
