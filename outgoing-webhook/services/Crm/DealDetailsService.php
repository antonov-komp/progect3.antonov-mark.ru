<?php
declare(strict_types=1);

/**
 * Сервис деталей сделок: форматирование и запись (файл + БД deal_details).
 * По каждому событию выполняется crm.deal.get → полный ответ сохраняется.
 * При наличии DealFieldsResolver пишется и details_resolved (пользовательское представление полей).
 */
class DealDetailsService
{
    private const SUPPORTED = ['ONCRMDEALADD', 'ONCRMDEALUPDATE'];

    private FilesystemService $filesystem;
    private RequestService $request;
    private LogValueFormatter $formatter;
    private ?DealDetailsRepository $repository;
    private ?DealFieldsResolver $resolver;
    private string $basePath;

    public function __construct(
        FilesystemService $filesystem,
        RequestService $request,
        LogValueFormatter $formatter,
        ?DealDetailsRepository $repository = null,
        ?DealFieldsResolver $resolver = null
    ) {
        $this->filesystem = $filesystem;
        $this->request = $request;
        $this->formatter = $formatter;
        $this->repository = $repository;
        $this->resolver = $resolver;
        $this->basePath = dirname(__DIR__, 2);
    }

    public function formatDetailsRu(array $deal, string $requestId, string $eventType): string
    {
        $id = $deal['ID'] ?? $deal['id'] ?? '?';
        $title = $this->formatter->normalize($deal['TITLE'] ?? $deal['title'] ?? '');
        $stage = $deal['STAGE_ID'] ?? $deal['stageId'] ?? '?';
        $amount = $deal['OPPORTUNITY'] ?? $deal['opportunity'] ?? '?';
        $currency = $deal['CURRENCY_ID'] ?? $deal['currencyId'] ?? '?';
        $created = $deal['DATE_CREATE'] ?? $deal['dateCreate'] ?? '?';
        $createdBy = $deal['CREATED_BY_ID'] ?? $deal['createdById'] ?? '?';

        return sprintf(
            'Дата=%s | requestId=%s | Событие=%s | Сделка=%s | Название=%s | Этап=%s | Сумма=%s %s | Создана=%s | Создатель=%s',
            $this->request->now(),
            $requestId,
            $eventType,
            $id,
            $title,
            $stage,
            $amount,
            $currency,
            $created,
            $createdBy
        );
    }

    /**
     * Записать детали сделки: лог-файл + БД deal_details.
     *
     * @param string $eventType ONCRMDEALADD | ONCRMDEALUPDATE
     * @param string $requestId
     * @param string $dealId
     * @param array $deal Полный объект сделки (crm.deal.get result)
     */
    public function writeDetailsRu(string $eventType, string $requestId, string $dealId, array $deal): void
    {
        if (!in_array($eventType, self::SUPPORTED, true)) {
            return;
        }

        $formatted = $this->formatDetailsRu($deal, $requestId, $eventType);

        $eventDir = $this->basePath . '/logs/' . $eventType;
        $this->filesystem->ensureDir($eventDir);
        $this->filesystem->appendLine($eventDir . '/deal-details.log', $formatted);

        if ($this->repository !== null) {
            $data = [
                'requestId' => $requestId,
                'eventType' => $eventType,
                'dealId' => $dealId,
                'details' => $deal,
                'formattedDetails' => $formatted,
                'createdAt' => $this->request->now(),
            ];
            if ($this->resolver !== null) {
                try {
                    $data['detailsResolved'] = $this->resolver->resolveDeal($deal);
                    $data['keyFields'] = $this->resolver->extractKeyFields($deal);
                } catch (Throwable $e) {
                    $data['detailsResolved'] = [];
                    $data['keyFields'] = [];
                }
            } else {
                $data['keyFields'] = [];
            }
            $this->repository->create($data);
        }
    }
}
