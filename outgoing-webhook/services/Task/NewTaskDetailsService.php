<?php
declare(strict_types=1);

/**
 * Сервис для сохранения полного снимка новой задачи (ONTASKADD)
 */
class NewTaskDetailsService
{
    private FilesystemService $filesystem;
    private RequestService $request;
    private ErrorService $errors;
    private ConfigService $config;
    private ?NewTaskDetailsRepository $repository;
    private string $basePath;
    private int $maxBytes;

    public function __construct(
        FilesystemService $filesystem,
        RequestService $request,
        ErrorService $errors,
        ConfigService $config,
        ?NewTaskDetailsRepository $repository = null
    ) {
        $this->filesystem = $filesystem;
        $this->request = $request;
        $this->errors = $errors;
        $this->config = $config;
        $this->repository = $repository;
        $this->basePath = dirname(__DIR__, 2);
        $this->maxBytes = (int) ($this->config->get('NEW_TASK_DETAILS_MAX_BYTES', '800000') ?? 800000);
    }

    /**
     * Сохраняет raw + extracted в БД и лог
     *
     * @param array $restResponse Полный ответ tasks.task.get (REST 3.0)
     */
    public function persist(string $eventType, string $requestId, string $taskId, array $restResponse): void
    {
        $createdAt = $this->request->now();
        [$rawJson, $rawTruncated] = $this->encodeRaw($restResponse);
        $taskData = $this->resolveTaskData($restResponse);
        $extracted = $this->buildExtracted($taskData, $eventType, $requestId, $taskId, $createdAt, $rawTruncated);

        $this->writeLog($eventType, $requestId, $taskId, $rawJson, $extracted);

        if ($this->repository !== null) {
            $this->repository->create([
                'requestId' => $requestId,
                'eventType' => $eventType,
                'taskId' => $taskId,
                'rawPayload' => $rawJson,
                'extracted' => $extracted,
                'createdAt' => $createdAt,
            ]);
        }
    }

    private function encodeRaw(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = json_encode(['error' => 'json_encode_failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $truncated = false;
        if ($this->maxBytes > 0 && strlen($json) > $this->maxBytes) {
            $json = substr($json, 0, $this->maxBytes);
            $truncated = true;
        }

        return [$json, $truncated];
    }

    private function resolveTaskData(array $restResponse): array
    {
        if (isset($restResponse['result']) && is_array($restResponse['result'])) {
            $result = $restResponse['result'];
            if (isset($result['task']) && is_array($result['task'])) {
                return $result['task'];
            }
            return $result;
        }

        if (isset($restResponse['task']) && is_array($restResponse['task'])) {
            return $restResponse['task'];
        }

        return $restResponse;
    }

    private function buildExtracted(
        array $task,
        string $eventType,
        string $requestId,
        string $taskId,
        string $createdAt,
        bool $rawTruncated
    ): array {
        $rv = fn(array $keys) => $this->request->getFirstValue($task, $keys);

        $accomplices = $this->normalizeArray($rv([
            'ACCOMPLICES', 'accomplices',
        ]));
        $auditors = $this->normalizeArray($rv([
            'AUDITORS', 'auditors',
        ]));

        return [
            'requestId' => $requestId,
            'eventType' => $eventType,
            'taskId' => $taskId,
            'createdAt' => $createdAt,
            'rawTruncated' => $rawTruncated,
            'id' => $rv([['task', 'id'], 'id', 'ID']),
            'title' => $rv([['task', 'title'], 'TITLE', 'title', 'NAME']),
            'description' => $rv([['task', 'description'], 'DESCRIPTION', 'description']),
            'status' => $rv(['STATUS', 'status', 'STATE', 'state']),
            'priority' => $rv(['PRIORITY', 'priority']),
            'createdBy' => $rv(['CREATED_BY', 'CREATED_BY_ID', 'createdBy', ['creator', 'id']]),
            'responsibleId' => $rv(['RESPONSIBLE_ID', 'responsibleId', ['responsible', 'id']]),
            'accomplices' => $accomplices,
            'auditors' => $auditors,
            'groupId' => $rv(['GROUP_ID', 'groupId', ['group', 'id']]),
            'projectId' => $rv(['PROJECT_ID', 'projectId']),
            'stageId' => $rv(['STAGE_ID', 'stageId', 'KANBAN_STATUS_ID']),
            'tags' => $this->normalizeArray($rv(['TAGS', 'tags'])),
            'checklist' => $this->normalizeArray($rv(['CHECKLIST', 'checkList', 'checklist'])),
            'crmLinks' => $this->normalizeArray($rv(['UF_CRM_TASK', 'crm', ['ufCrmTask']]), true),
            'files' => $this->normalizeArray($rv(['FILES', 'files', 'UF_TASK_WEBDAV_FILES', ['ufTaskWebdavFiles']])),
            'attachments' => $this->normalizeArray($rv(['ATTACHMENTS', 'attachments'])),
            'dates' => [
                'created' => $rv(['CREATED_DATE', 'createdDate', ['createdTime']]),
                'deadline' => $rv(['DEADLINE', 'deadline']),
                'startDatePlan' => $rv(['START_DATE_PLAN', 'startDatePlan']),
                'endDatePlan' => $rv(['END_DATE_PLAN', 'endDatePlan']),
                'closedDate' => $rv(['CLOSED_DATE', 'closedDate']),
                'changedDate' => $rv(['CHANGED_DATE', 'changedDate']),
            ],
            'time' => [
                'timeSpent' => $rv(['TIME_SPENT_IN_LOGS', 'timeSpentInLogs', 'TIME_SPENT']),
                'timeEstimate' => $rv(['TIME_ESTIMATE', 'timeEstimate']),
            ],
            'uf' => $this->extractUserFields($task),
        ];
    }

    private function normalizeArray($value, bool $forceString = false): array
    {
        if (!is_array($value)) {
            if ($value === null || $value === '') {
                return [];
            }
            return [$forceString ? (string) $value : $value];
        }

        return array_values(array_filter(array_map(
            fn($item) => $forceString ? (string) $item : $item,
            $value
        ), fn($item) => $item !== null && $item !== ''));
    }

    private function extractUserFields(array $task): array
    {
        $uf = [];
        foreach ($task as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'UF_')) {
                $uf[$key] = $value;
            }
        }

        return $uf;
    }

    private function writeLog(string $eventType, string $requestId, string $taskId, string $rawJson, array $extracted): void
    {
        $logDir = $this->basePath . '/logs/ONTASKADD_NEW';
        $this->filesystem->ensureDir($logDir);

        $entry = [
            'requestId' => $requestId,
            'eventType' => $eventType,
            'taskId' => $taskId,
            'loggedAt' => $this->request->now(),
            'rawSize' => strlen($rawJson),
            'extracted' => $extracted,
        ];

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = json_encode([
                'requestId' => $requestId,
                'eventType' => $eventType,
                'taskId' => $taskId,
                'error' => 'json_encode_failed',
            ]);
        }

        if (!$this->filesystem->appendLine($logDir . '/task-snapshot.log', (string) $json)) {
            $this->errors->log('Failed to write ONTASKADD_NEW log', [
                'requestId' => $requestId,
                'taskId' => $taskId,
            ]);
        }
    }
}
