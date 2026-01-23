<?php
declare(strict_types=1);

class StateStorage
{
    private string $stateDir;

    public function __construct(string $stateDir)
    {
        $this->stateDir = $stateDir;
    }

    public function getPath(string $entityType, string $entityId): string
    {
        return $this->stateDir . '/' . $entityType . '_' . $entityId . '.json';
    }

    public function detectFieldChanges(string $entityType, string $entityId, array $current, string $eventType): void
    {
        $statePath = $this->getPath($entityType, $entityId);
        $previous = null;
        if (file_exists($statePath)) {
            $previous = json_decode((string) file_get_contents($statePath), true);
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
                $changesDir = dirname($this->stateDir) . '/field-changes';
                outgoingWebhookSafeMkdir($changesDir);

                $entry = [
                    'changedAt' => outgoingWebhookNow(),
                    'eventType' => $eventType,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'changes' => $changes,
                ];

                $changeFile = sprintf(
                    '%s/%s_%s_%s.json',
                    $changesDir,
                    $entityType,
                    $entityId,
                    date('Ymd_His')
                );

                outgoingWebhookWriteJson($changeFile, $entry);
                outgoingWebhookAppendLine($changesDir . '/field-changes.log', json_encode($entry, JSON_UNESCAPED_SLASHES));
            }
        }

        outgoingWebhookWriteJson($statePath, [
            'savedAt' => outgoingWebhookNow(),
            'entityType' => $entityType,
            'entityId' => $entityId,
            'data' => $current,
        ]);
    }
}
