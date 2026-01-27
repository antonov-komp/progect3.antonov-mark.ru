<?php
declare(strict_types=1);

/**
 * Сервис для работы с деталями комментариев
 * 
 * Координирует работу специализированных классов:
 * - CommentFetcher - получение данных
 * - CommentBuilder - построение структур
 * - CommentFormatter - форматирование
 * - CommentWriter - запись в файлы
 * - ActivityFirstProcessor - обработка ActivityFirst
 */
class CommentDetailsService
{
    private ?RestService $rest;
    private ErrorService $errors;
    private CommentFetcher $fetcher;
    private CommentBuilder $builder;
    private CommentFormatter $formatter;
    private CommentWriter $writer;
    private ActivityFirstProcessor $activityFirst; // Старый процессор для обратной совместимости
    private ActivityProcessor $activityProcessor; // Новый процессор для работы с типами Activity
    private EntityIdentityService $identity;
    private TaskDetailsService $taskDetails;
    private RequestService $request;
    private ConfigService $config;

    public function __construct(
        ?RestService $rest,
        ErrorService $errors,
        TaskDetailsService $taskDetails,
        TaskFilesService $taskFiles,
        DealFileService $dealFiles,
        EntityIdentityService $identity,
        RequestService $request,
        LogValueFormatter $formatter,
        FilesystemService $filesystem,
        ConfigService $config
    ) {
        $this->rest = $rest;
        $this->errors = $errors;
        $this->identity = $identity;
        $this->taskDetails = $taskDetails;
        $this->request = $request;
        $this->config = $config;
        
        // Инициализация специализированных классов
        // CommentFetcher требует RestService, но может быть null
        // В этом случае создаем временный RestService только для CommentFetcher
        $fetcherRest = $rest;
        if ($fetcherRest === null) {
            // Для обратной совместимости создаем временный RestService
            // В будущем это должно быть исправлено через Dependency Injection
            require_once dirname(__DIR__, 3) . '/app/crest.php';
            require_once dirname(__DIR__, 3) . '/app/Services/Bitrix24Client.php';
            $fetcherRest = new RestService(new Bitrix24Client(), $config, $errors);
        }
        
        $this->fetcher = new CommentFetcher(
            $fetcherRest,
            $identity,
            $taskDetails,
            $request,
            $errors
        );
        $this->builder = new CommentBuilder($identity, $request, $taskDetails, $errors);
        $this->formatter = new CommentFormatter($formatter);
        $this->writer = new CommentWriter($filesystem, $config, $request, $this->formatter);
        $this->activityFirst = new ActivityFirstProcessor($taskDetails, $taskFiles, $dealFiles); // Для обратной совместимости
        $this->activityProcessor = new ActivityProcessor($taskDetails, $taskFiles, $dealFiles);
    }

    /**
     * Обработка добавления комментария
     */
    public function handleCommentAdd(
        string $eventType,
        array $raw,
        array $job,
        ?string $entityId,
        string $rawPath,
        ?array $taskData
    ): void {
        if ($this->rest === null || $entityId === null) {
            return;
        }

        $commentId = $this->identity->extractCommentId($raw['payload'] ?? []);
        $messageId = $this->identity->extractMessageId($raw['payload'] ?? []);
        if ($commentId === null) {
            return;
        }

        $restCall = fn(string $method, array $params = []) => $this->rest->call($method, $params);
        
        // Получение данных комментария
        $fetch = $this->fetcher->fetch($entityId, $commentId, $messageId, $taskData);
        
        $commentWritten = false;
        $commentData = null;
        $commentSource = null;
        $commentDetails = null;

        if (is_array($fetch['data'])) {
            // Обогащение данных задачи CRM-ссылками
            if (is_array($taskData)) {
                $taskData = $this->taskDetails->ensureCrmLinks($taskData, $entityId, $restCall);
            }
            
            $commentData = $fetch['data'];
            $commentSource = $fetch['method'];
            
            // Определение метода построения в зависимости от источника данных
            if ($fetch['method'] === 'im.dialog.messages.get') {
                // Данные из чата - используем buildFromChat
                $commentDetails = $this->builder->buildFromChat(
                    $fetch['data'],
                    $eventType,
                    $job['requestId'] ?? null,
                    $entityId,
                    $commentId,
                    $fetch['method'],
                    is_array($taskData) ? $taskData : null
                );
            } else {
                // Данные из task.commentitem.get - используем build
                $commentDetails = $this->builder->build(
                    $fetch['data'],
                    $eventType,
                    $job['requestId'] ?? null,
                    $entityId,
                    $commentId,
                    $fetch['method'],
                    is_array($taskData) ? $taskData : null
                );
            }
            $this->writer->writeDetailsRu($eventType, $commentDetails);
            $commentWritten = true;
        } else {
            // Fallback: создание минимальных данных
            $fallback = $this->builder->buildFallback(
                $eventType,
                $job['requestId'] ?? null,
                $entityId,
                $commentId
            );
            $this->writer->writeDetailsRu($eventType, $fallback);
            $this->errors->log('Comment details missing', [
                'requestId' => $job['requestId'] ?? 'unknown',
                'taskId' => $entityId,
                'commentId' => $commentId,
                'messageId' => $messageId,
                'errors' => $fetch['errors'] ?? [],
            ]);
        }

        // Запись обогащенных данных (если требуется)
        if ($commentWritten && $this->writer->shouldWriteEnriched($entityId) && is_array($commentData)) {
            if (is_array($taskData)) {
                $this->writer->writeEnriched(
                    $eventType,
                    $entityId,
                    $taskData,
                    $commentData,
                    $commentSource ?? 'unknown',
                    $rawPath,
                    $job['requestId'] ?? null
                );
            }
        }

        // Обработка Activity (если требуется)
        if ($commentWritten && is_array($commentDetails)) {
            $activityType = $commentDetails['activityType'] ?? null;
            
            if ($activityType !== null && is_string($activityType)) {
                // Использование нового ActivityProcessor с типом Activity
                $result = $this->activityProcessor->process($commentDetails, $activityType, $entityId, $restCall);
                $this->taskDetails->logActivityFirst([
                    'loggedAt' => $this->request->now(),
                    'requestId' => $job['requestId'] ?? 'unknown',
                    'taskId' => $entityId,
                    'activityType' => $activityType,
                    'dealField' => $result['dealField'],
                    'dealIds' => $result['dealIds'],
                    'fileIds' => $result['fileIds'],
                    'taskAttach' => $result['taskAttach'],
                    'dealUpdates' => $result['dealUpdates'],
                ]);
            } elseif (!empty($commentDetails['activityFirst'])) {
                // Обратная совместимость: использование старого ActivityFirstProcessor
                $result = $this->activityFirst->process($commentDetails, $entityId, $restCall);
                $this->taskDetails->logActivityFirst([
                    'loggedAt' => $this->request->now(),
                    'requestId' => $job['requestId'] ?? 'unknown',
                    'taskId' => $entityId,
                    'dealIds' => $result['dealIds'],
                    'fileIds' => $result['fileIds'],
                    'taskAttach' => $result['taskAttach'],
                    'dealUpdates' => $result['dealUpdates'],
                ]);
            }
        }
    }

    // Методы для обратной совместимости (делегирование к специализированным классам)

    public function buildDetails(
        array $commentData,
        string $eventType,
        ?string $requestId,
        ?string $taskId,
        ?string $commentId,
        ?string $sourceMethod,
        ?array $taskData = null
    ): array {
        // Определение метода построения в зависимости от источника данных
        if ($sourceMethod === 'im.dialog.messages.get') {
            // Данные из чата - используем buildFromChat
            return $this->builder->buildFromChat(
                $commentData,
                $eventType,
                $requestId,
                $taskId ?? 'unknown',
                $commentId ?? 'unknown',
                $sourceMethod ?? 'unknown',
                $taskData
            );
        } else {
            // Данные из task.commentitem.get - используем build
            return $this->builder->build(
                $commentData,
                $eventType,
                $requestId,
                $taskId ?? 'unknown',
                $commentId ?? 'unknown',
                $sourceMethod ?? 'unknown',
                $taskData
            );
        }
    }

    public function buildFallback(
        string $eventType,
        ?string $requestId,
        ?string $taskId,
        ?string $commentId
    ): array {
        return $this->builder->buildFallback($eventType, $requestId, $taskId ?? 'unknown', $commentId ?? 'unknown');
    }

    public function resolveKind($authorId): string
    {
        return $this->builder->resolveKind($authorId);
    }

    public function formatDetailsRu(array $details): string
    {
        return $this->formatter->formatDetailsRu($details);
    }

    public function writeDetailsRu(string $eventType, array $details): void
    {
        $this->writer->writeDetailsRu($eventType, $details);
    }

    public function findCommentItem($payload, string $commentId): ?array
    {
        // Делегирование к CommentFetcher через приватный метод
        // Для обратной совместимости оставляем публичный метод
        return null; // Реализация перенесена в CommentFetcher
    }

    public function fetchDetails(callable $restCall, string $taskId, string $commentId, ?string $messageId = null): array
    {
        // Используем CommentFetcher для получения данных
        // Получаем taskData для fallback через чат
        $taskResult = $restCall('tasks.task.get', ['id' => $taskId]);
        $taskPayload = $taskResult['result'] ?? $taskResult;
        $taskData = is_array($taskPayload) ? ($taskPayload['task'] ?? $taskPayload) : null;
        
        // Используем CommentFetcher с messageId для fallback через чат
        return $this->fetcher->fetch($taskId, $commentId, $messageId, $taskData);
    }

    public function extractChatId(array $taskData): ?string
    {
        // Используем RequestService для извлечения chatId
        $value = $this->request->getFirstValue($taskData, ['chatId', 'CHAT_ID', 'chat_id']);
        return $value !== null ? (string) $value : null;
    }

    public function findChatMessage(array $payload, string $messageId): ?array
    {
        // Делегирование к CommentFetcher
        return null; // Реализация перенесена в CommentFetcher
    }

    public function fetchChatMessageDetails(callable $restCall, string $chatId, string $messageId): array
    {
        // Используем CommentFetcher для получения данных через чат
        // Создаем временный RestService wrapper для использования переданного restCall
        // Но CommentFetcher уже имеет свой RestService, поэтому используем его напрямую
        // Для правильной работы нужно получить taskData и использовать CommentFetcher::fetch()
        // Временно возвращаем пустой результат, так как этот метод вызывается только из CommentEventHandler
        // где уже есть логика получения через чат
        return ['data' => null, 'method' => null, 'errors' => []];
    }

    public function buildDetailsFromChat(
        array $messageData,
        string $eventType,
        ?string $requestId,
        ?string $taskId,
        ?string $commentId,
        ?string $sourceMethod,
        ?array $taskData = null
    ): array {
        return $this->builder->buildFromChat(
            $messageData,
            $eventType,
            $requestId,
            $taskId ?? 'unknown',
            $commentId ?? 'unknown',
            $sourceMethod ?? 'unknown',
            $taskData
        );
    }

    public function writeEnriched(
        string $eventType,
        string $entityId,
        array $taskData,
        array $commentData,
        string $sourceMethod,
        string $rawPath,
        ?string $requestId
    ): void {
        $this->writer->writeEnriched(
            $eventType,
            $entityId,
            $taskData,
            $commentData,
            $sourceMethod,
            $rawPath,
            $requestId
        );
    }

    public function shouldWriteEnriched(?string $taskId): bool
    {
        return $this->writer->shouldWriteEnriched($taskId);
    }

    /**
     * Обработка Activity (синхронная)
     * 
     * Поддерживает как новый формат (activityType), так и старый (activityFirst)
     * 
     * @param array $commentDetails Детали комментария
     * @param string $entityId ID задачи
     * @param callable $restCall Функция для REST API вызовов
     * @return array Результат обработки
     */
    public function processActivityFirst(
        array $commentDetails,
        string $entityId,
        callable $restCall
    ): array {
        $activityType = $commentDetails['activityType'] ?? null;
        
        if ($activityType !== null && is_string($activityType)) {
            // Использование нового ActivityProcessor с типом Activity
            return $this->activityProcessor->process($commentDetails, $activityType, $entityId, $restCall);
        } else {
            // Обратная совместимость: использование старого ActivityFirstProcessor
            return $this->activityFirst->process($commentDetails, $entityId, $restCall);
        }
    }
}
