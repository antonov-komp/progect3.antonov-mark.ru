<?php
declare(strict_types=1);

/**
 * Форматирование деталей комментария
 * 
 * Ответственность:
 * - Форматирование деталей комментария для логов
 */
class CommentFormatter
{
    private LogValueFormatter $formatter;

    public function __construct(LogValueFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    /**
     * Форматирование деталей для логов (RU)
     */
    public function formatDetailsRu(array $details): string
    {
        $files = $details['fileIds'] ?? [];
        $filesText = is_array($files) && !empty($files)
            ? implode(',', $files)
            : 'нет';
        $crmLinks = $details['crmLinks'] ?? [];
        $crmText = is_array($crmLinks) && !empty($crmLinks)
            ? implode(',', $crmLinks)
            : 'нет';

        $activityFirst = !empty($details['activityFirst']) ? 'да' : 'нет';

        return sprintf(
            'Дата=%s | requestId=%s | Событие=%s | Задача=%s | Проект=%s (%s) | CRM=%s | КомментарийID=%s | Автор=%s | Тип=%s | Создано=%s | Текст=%s | Файлы=%s | ActivityFirst=%s | Метод=%s',
            $details['loggedAt'] ?? 'unknown',
            $details['requestId'] ?? 'unknown',
            $details['eventType'] ?? 'unknown',
            $details['taskId'] ?? 'unknown',
            $this->formatter->normalize($details['projectName'] ?? 'unknown'),
            $details['projectId'] ?? 'unknown',
            $crmText,
            $details['commentId'] ?? 'unknown',
            $details['authorId'] ?? 'unknown',
            $details['commentKind'] ?? 'unknown',
            $details['createdAt'] ?? 'unknown',
            $this->formatter->normalize($details['message'] ?? 'unknown'),
            $filesText,
            $activityFirst,
            $details['sourceMethod'] ?? 'unknown'
        );
    }
}
