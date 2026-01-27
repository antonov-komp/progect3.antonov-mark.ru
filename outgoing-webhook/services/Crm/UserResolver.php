<?php
declare(strict_types=1);

/**
 * Резолвер пользователей: user.get → «Имя Фамилия» (+ ID).
 * Кэш через DictCache (user_1619 и т.д.).
 */
class UserResolver
{
    private DictCacheService $dicts;
    private int $dictTtl = 3600;

    public function __construct(DictCacheService $dicts)
    {
        $this->dicts = $dicts;
    }

    /**
     * Получить отображаемое имя пользователя: «Имя Фамилия» или «ID 123» при отсутствии данных.
     */
    public function getDisplayName(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '' || $userId === '0') {
            return '';
        }

        $data = $this->dicts->get('user_' . $userId, 'user.get', [
            'FILTER' => ['ID' => $userId],
        ], $this->dictTtl);

        if (!is_array($data)) {
            return 'ID ' . $userId;
        }

        $list = $data;
        $user = isset($list[0]) && is_array($list[0]) ? $list[0] : [];
        $name = isset($user['NAME']) ? trim((string) $user['NAME']) : '';
        $last = isset($user['LAST_NAME']) ? trim((string) $user['LAST_NAME']) : '';

        $full = trim($name . ' ' . $last);
        return $full !== '' ? $full : 'ID ' . $userId;
    }

    /**
     * Несколько ID → [ id => «Имя Фамилия», ... ].
     *
     * @param list<string> $userIds
     * @return array<string, string>
     */
    public function getDisplayNames(array $userIds): array
    {
        $out = [];
        foreach (array_unique(array_filter($userIds)) as $id) {
            $out[(string) $id] = $this->getDisplayName((string) $id);
        }
        return $out;
    }
}
