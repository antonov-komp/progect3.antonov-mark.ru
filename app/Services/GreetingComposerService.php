<?php

class GreetingComposerService
{
    private const DEFAULT_GREETING = 'Привет!';

    public function buildGreeting(string $name, string $lastName, string $status): string
    {
        if ($status === 'error') {
            return self::DEFAULT_GREETING;
        }

        $trimmedName = trim($name);
        if ($trimmedName === '') {
            return self::DEFAULT_GREETING;
        }

        $trimmedLastName = trim($lastName);
        $fullName = $trimmedName;
        if ($trimmedLastName !== '') {
            $fullName .= ' ' . $trimmedLastName;
        }

        return 'Привет, ' . $fullName . '!';
    }

    public function buildContextMessage(?bool $isAdmin, bool $isEmbedded, string $departmentName, string $tokenOwnerName = ''): string
    {
        if (!$isEmbedded && $tokenOwnerName !== '') {
            $departmentLabel = $departmentName !== '' ? $departmentName : 'не указан';
            return 'Владелец токена: ' . $tokenOwnerName . '; ' .
                'контекст: по прямой ссылке; ' .
                'отдел: ' . $departmentLabel . '.';
        }

        $adminLabel = 'неизвестно';
        if ($isAdmin === true) {
            $adminLabel = 'да';
        } elseif ($isAdmin === false) {
            $adminLabel = 'нет';
        }

        $contextLabel = $isEmbedded ? 'внутри Bitrix24' : 'по прямой ссылке';
        $departmentLabel = $departmentName !== '' ? $departmentName : 'не указан';

        return 'Администратор портала: ' . $adminLabel . '; ' .
            'контекст: ' . $contextLabel . '; ' .
            'отдел: ' . $departmentLabel . '.';
    }
}
