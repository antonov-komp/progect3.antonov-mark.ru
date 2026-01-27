<?php
declare(strict_types=1);

class StateStorage
{
    private string $stateDir;
    private ?EntityStateRepository $entityStateRepo;
    private ?EntityFieldChangesRepository $fieldChangesRepo;
    private ?DealFieldsResolver $dealResolver;

    public function __construct(
        string $stateDir,
        ?EntityStateRepository $entityStateRepo = null,
        ?EntityFieldChangesRepository $fieldChangesRepo = null,
        ?DealFieldsResolver $dealResolver = null
    ) {
        $this->stateDir = $stateDir;
        $this->entityStateRepo = $entityStateRepo;
        $this->fieldChangesRepo = $fieldChangesRepo;
        $this->dealResolver = $dealResolver;
    }

    public function getPath(string $entityType, string $entityId): string
    {
        return $this->stateDir . '/' . $entityType . '_' . $entityId . '.json';
    }

    public function detectFieldChanges(string $entityType, string $entityId, array $current, string $eventType): void
    {
        $useDb = $this->entityStateRepo !== null && $this->fieldChangesRepo !== null;

        $previous = null;
        if ($useDb) {
            $row = $this->entityStateRepo->get($entityType, $entityId);
            if (is_array($row) && isset($row['state']) && is_array($row['state'])) {
                $previous = $row['state'];
            }
        } else {
            $statePath = $this->getPath($entityType, $entityId);
            if (file_exists($statePath)) {
                $previous = json_decode((string) file_get_contents($statePath), true);
            }
        }

        if (is_array($previous) && isset($previous['data']) && is_array($previous['data'])) {
            $before = $previous['data'];
            $after = $current;
            $fields = array_unique(array_merge(array_keys($before), array_keys($after)));

            $changes = [];
            foreach ($fields as $field) {
                $oldValue = $before[$field] ?? null;
                $newValue = $after[$field] ?? null;
                if (!ValueComparator::valuesEqual($oldValue, $newValue)) {
                    $changes[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }

            if (!empty($changes)) {
                $changedAt = outgoingWebhookNow();
                $changesResolved = null;
                if ($entityType === 'deal' && $this->dealResolver !== null) {
                    $changesResolved = [];
                    foreach ($changes as $field => $v) {
                        $oldVal = is_array($v) ? ($v['old'] ?? null) : null;
                        $newVal = is_array($v) ? ($v['new'] ?? null) : null;
                        try {
                            $r = $this->dealResolver->resolveChangeForField($field, $oldVal, $newVal);
                            $changesResolved[$field] = [
                                'title' => $r['title'],
                                'old_display' => $r['old_display'],
                                'new_display' => $r['new_display'],
                            ];
                        } catch (Throwable $e) {
                            $changesResolved[$field] = [
                                'title' => $field,
                                'old_display' => is_array($oldVal) ? json_encode($oldVal, JSON_UNESCAPED_UNICODE) : (string) $oldVal,
                                'new_display' => is_array($newVal) ? json_encode($newVal, JSON_UNESCAPED_UNICODE) : (string) $newVal,
                            ];
                        }
                    }
                }
                if ($useDb) {
                    $this->fieldChangesRepo->create($entityType, $entityId, $eventType, $changedAt, $changes, $changesResolved);
                } else {
                    $changesDir = dirname($this->stateDir) . '/field-changes';
                    outgoingWebhookSafeMkdir($changesDir);
                    $entry = [
                        'changedAt' => $changedAt,
                        'eventType' => $eventType,
                        'entityType' => $entityType,
                        'entityId' => $entityId,
                        'changes' => $changes,
                    ];
                    if ($changesResolved !== null) {
                        $entry['changesResolved'] = $changesResolved;
                    }
                    $changeFile = sprintf(
                        '%s/%s_%s_%s.json',
                        $changesDir,
                        $entityType,
                        $entityId,
                        date('Ymd_His')
                    );
                    outgoingWebhookWriteJson($changeFile, $entry);
                    outgoingWebhookAppendLine($changesDir . '/field-changes.log', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
        }

        $state = [
            'savedAt' => outgoingWebhookNow(),
            'entityType' => $entityType,
            'entityId' => $entityId,
            'data' => $current,
        ];

        if ($useDb) {
            $this->entityStateRepo->save($entityType, $entityId, $state);
        } else {
            outgoingWebhookWriteJson($this->getPath($entityType, $entityId), $state);
        }
    }
}
