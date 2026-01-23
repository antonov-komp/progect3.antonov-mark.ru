<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';
require_once __DIR__ . '/../../app/crest.php';
require_once __DIR__ . '/../../app/Services/Bitrix24Client.php';

const OUTGOING_WEBHOOK_MAX_ATTEMPTS = 3;
const OUTGOING_WEBHOOK_PROCESSING_TIMEOUT = 900; // 15 minutes

function outgoingWebhookGetServices(): array
{
    static $services = null;
    if ($services !== null) {
        return $services;
    }

    $baseDir = dirname(__DIR__);
    $queueDir = $baseDir . '/queue';
    $logsDir = $baseDir . '/logs';

    $config = new ConfigService();
    $errors = new ErrorService();
    $rest = new RestService(new Bitrix24Client(), $config, $errors);
    $dicts = new DictCacheService($rest, $errors, $logsDir . '/dicts');
    $stateStorage = new StateStorage($logsDir . '/state');

    $handlers = [
        new DealHandler($dicts),
        new LeadHandler($dicts),
        new SmartProcessHandler($dicts),
        new TaskHandler(),
        new UserHandler(),
        new ProjectHandler(),
        new CrmUserFieldHandler(),
        new ContactHandler(),
        new CompanyHandler(),
    ];

    $enrichment = new EnrichmentService($rest, $errors, $stateStorage, $handlers);

    $queue = new QueueService(
        $queueDir . '/pending',
        $queueDir . '/processing',
        $queueDir . '/done',
        $queueDir . '/failed'
    );
    $jobState = new JobStateService($queue, $errors, OUTGOING_WEBHOOK_MAX_ATTEMPTS, OUTGOING_WEBHOOK_PROCESSING_TIMEOUT);

    $steps = new QueueStepLogger($logsDir . '/queue-steps.log');
    $taskDetails = new TaskDetailsService();
    $commentDetails = new CommentDetailsService($rest, $errors);

    $runner = new QueueRunner(
        $queue,
        $jobState,
        $enrichment,
        $errors,
        $steps,
        $taskDetails,
        $commentDetails
    );

    $services = [
        'config' => $config,
        'errors' => $errors,
        'rest' => $rest,
        'dicts' => $dicts,
        'state' => $stateStorage,
        'enrichment' => $enrichment,
        'queue' => $queue,
        'jobState' => $jobState,
        'steps' => $steps,
        'taskDetails' => $taskDetails,
        'commentDetails' => $commentDetails,
        'runner' => $runner,
    ];

    return $services;
}

function outgoingWebhookRestCall(string $method, array $params = []): array
{
    return outgoingWebhookGetServices()['rest']->call($method, $params);
}

function outgoingWebhookCountQueue(string $dir): int
{
    return outgoingWebhookGetServices()['queue']->count($dir);
}

function outgoingWebhookResolveMethod(string $eventType): ?string
{
    return outgoingWebhookGetServices()['enrichment']->resolveMethod($eventType);
}

function outgoingWebhookReadDict(string $path, int $ttlSeconds): ?array
{
    return outgoingWebhookGetServices()['dicts']->read($path, $ttlSeconds);
}

function outgoingWebhookWriteDict(string $path, array $data): void
{
    outgoingWebhookGetServices()['dicts']->write($path, $data);
}

function outgoingWebhookGetDict(
    string $name,
    string $method,
    array $params,
    int $ttlSeconds
): ?array {
    return outgoingWebhookGetServices()['dicts']->get($name, $method, $params, $ttlSeconds);
}

function outgoingWebhookExtractEntityTypeId(array $payload): ?string
{
    return outgoingWebhookGetServices()['enrichment']->extractEntityTypeId($payload);
}

function outgoingWebhookBuildEnriched(
    string $eventType,
    string $entityType,
    ?string $entityId,
    array $raw,
    string $rawPath
): array {
    return outgoingWebhookGetServices()['enrichment']->buildEnriched($eventType, $entityType, $entityId, $raw, $rawPath);
}

function outgoingWebhookMoveToFailed(string $processingPath, array $job, string $reason, ?string $method = null): void
{
    $service = outgoingWebhookGetServices()['jobState'];
    $service->markFailed(new QueueJob($processingPath, $job), $reason, $method);
}

function outgoingWebhookSortRecursive($value)
{
    return ValueComparator::sortRecursive($value);
}

function outgoingWebhookValuesEqual($left, $right): bool
{
    return ValueComparator::valuesEqual($left, $right);
}

function outgoingWebhookGetStatePath(string $entityType, string $entityId): string
{
    return outgoingWebhookGetServices()['state']->getPath($entityType, $entityId);
}

function outgoingWebhookDetectFieldChanges(string $entityType, string $entityId, array $current, string $eventType): void
{
    outgoingWebhookGetServices()['enrichment']->detectFieldChanges($entityType, $entityId, $current, $eventType);
}

function outgoingWebhookRecoverProcessing(): void
{
    outgoingWebhookGetServices()['jobState']->recoverProcessing();
}

$limit = (int) ($_GET['limit'] ?? 50);
if ($limit <= 0) {
    $limit = 50;
}

$result = outgoingWebhookGetServices()['runner']->run($limit);
outgoingWebhookJsonResponse(200, $result);
