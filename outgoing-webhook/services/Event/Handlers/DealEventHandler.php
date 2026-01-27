<?php
declare(strict_types=1);

/**
 * Обработчик событий сделок (ONCRMDEALADD, ONCRMDEALUPDATE)
 *
 * Ответственность:
 * - Получение актуальных данных сделки через crm.deal.get
 * - Сравнение с предыдущим состоянием (что было → что стало)
 * - Запись изменений в logs/field-changes/ и обновление state
 */
class DealEventHandler
{
    private RestService $rest;
    private StateStorage $stateStorage;
    private ErrorService $errors;

    private const SUPPORTED = ['ONCRMDEALADD', 'ONCRMDEALUPDATE'];

    public function __construct(
        RestService $rest,
        StateStorage $stateStorage,
        ErrorService $errors
    ) {
        $this->rest = $rest;
        $this->stateStorage = $stateStorage;
        $this->errors = $errors;
    }

    public function handle(string $eventType, ?string $entityId, string $requestId): void
    {
        if (!in_array($eventType, self::SUPPORTED, true)) {
            return;
        }

        if ($entityId === null || $entityId === '') {
            $this->errors->log('Deal handler: skip, no entityId', [
                'requestId' => $requestId,
                'eventType' => $eventType,
            ]);
            return;
        }

        try {
            $result = $this->rest->call('crm.deal.get', ['id' => $entityId]);

            if (!empty($result['error'])) {
                $this->errors->log('Deal handler: crm.deal.get error', [
                    'requestId' => $requestId,
                    'dealId' => $entityId,
                    'error' => $result['error'],
                ]);
                return;
            }

            $raw = $result['result'] ?? null;
            $current = is_array($raw) ? $raw : [];
            if ($current === []) {
                $this->errors->log('Deal handler: empty deal data', [
                    'requestId' => $requestId,
                    'dealId' => $entityId,
                ]);
                return;
            }

            $this->stateStorage->detectFieldChanges('deal', $entityId, $current, $eventType);
        } catch (Throwable $e) {
            $this->errors->log('Deal handler exception', [
                'requestId' => $requestId,
                'dealId' => $entityId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
