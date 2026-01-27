<?php
declare(strict_types=1);

/**
 * Преобразование сырых полей сделки в пользовательское представление:
 * - человеческие названия полей (crm.deal.fields);
 * - типы полей;
 * - отображаемые значения для списков (стадии, категории, enum из fields).
 */
class DealFieldsResolver
{
    private DictCacheService $dicts;
    private ?UserResolver $userResolver;
    private int $dictTtl = 86400;

    public function __construct(DictCacheService $dicts, ?UserResolver $userResolver = null)
    {
        $this->dicts = $dicts;
        $this->userResolver = $userResolver;
    }

    /**
     * Вернуть метаданные: fields, stages map, categories map.
     *
     * @return array{fields: array, stages: array<string, string>, categories: array<string, string>}
     */
    private function loadMeta(): array
    {
        $fieldsRaw = $this->dicts->get('deal_fields', 'crm.deal.fields', [], $this->dictTtl);
        $fields = is_array($fieldsRaw) ? $fieldsRaw : [];

        $stagesList = $this->dicts->get(
            'deal_stages',
            'crm.status.list',
            ['filter' => ['ENTITY_ID' => 'DEAL_STAGE']],
            $this->dictTtl
        );
        $stages = $this->buildStagesMap($stagesList);

        $categoriesRaw = $this->dicts->get(
            'deal_categories',
            'crm.category.list',
            ['entityTypeId' => 2],
            $this->dictTtl
        );
        $categories = $this->buildCategoriesMap($categoriesRaw);

        return ['fields' => $fields, 'stages' => $stages, 'categories' => $categories];
    }

    /**
     * @param array|mixed $list
     * @return array<string, string> STATUS_ID или C1:STATUS_ID -> NAME
     */
    private function buildStagesMap($list): array
    {
        $map = [];
        if (!is_array($list)) {
            return $map;
        }
        $items = isset($list['result']) && is_array($list['result']) ? $list['result'] : $list;
        if (isset($items['data']) && is_array($items['data'])) {
            $items = $items['data'];
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['STATUS_ID'] ?? $item['ID'] ?? null;
            $name = $item['NAME'] ?? $item['VALUE'] ?? null;
            if ($id === null || $name === null) {
                continue;
            }
            $id = (string) $id;
            $name = (string) $name;
            $map[$id] = $name;
            $cat = $item['CATEGORY_ID'] ?? null;
            if ($cat !== null && $cat !== '' && $cat !== '0') {
                $map[(string) $cat . ':' . $id] = $name;
            }
        }
        return $map;
    }

    /**
     * @param array|mixed $raw
     * @return array<string, string> id -> name
     */
    private function buildCategoriesMap($raw): array
    {
        $map = [];
        if (!is_array($raw)) {
            return $map;
        }
        $cats = $raw['categories'] ?? $raw['result']['categories'] ?? $raw['result'] ?? $raw;
        if (!is_array($cats)) {
            return $map;
        }
        foreach ($cats as $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = $c['id'] ?? $c['ID'] ?? null;
            $name = $c['name'] ?? $c['NAME'] ?? null;
            if ($id !== null && $name !== null) {
                $map[(string) $id] = (string) $name;
            }
        }
        return $map;
    }

    /**
     * Человеческое название поля: formLabel / listLabel приоритетнее title (для UF_*).
     */
    private function pickFieldTitle(string $code, $spec): string
    {
        if (!is_array($spec)) {
            return $code;
        }
        $form = $spec['formLabel'] ?? null;
        $list = $spec['listLabel'] ?? null;
        $t = $spec['title'] ?? null;
        if ($form !== null && $form !== '') {
            return (string) $form;
        }
        if ($list !== null && $list !== '') {
            return (string) $list;
        }
        return $t !== null && $t !== '' ? (string) $t : $code;
    }

    /**
     * Построить map enum для поля из crm.deal.fields (list / items).
     *
     * @param array|mixed $list
     * @return array<string, string> raw value -> display
     */
    private function buildListMap($list): array
    {
        $map = [];
        if (!is_array($list)) {
            return $map;
        }
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['ID'] ?? $item['STATUS_ID'] ?? null;
            $val = $item['VALUE'] ?? $item['NAME'] ?? null;
            if ($id !== null && $val !== null) {
                $map[(string) $id] = (string) $val;
            }
        }
        return $map;
    }

    /**
     * Преобразовать сырую сделку в массив «пользовательских» полей.
     *
     * @param array<string, mixed> $deal
     * @return array<int, array{code: string, title: string, type: string, raw: mixed, display: string}>
     */
    public function resolveDeal(array $deal): array
    {
        $meta = $this->loadMeta();
        $fields = $meta['fields'];
        $stages = $meta['stages'];
        $categories = $meta['categories'];

        $out = [];
        foreach ($deal as $code => $raw) {
            $spec = $fields[$code] ?? null;
            $title = $this->pickFieldTitle($code, $spec);
            $type = is_array($spec) ? ($spec['type'] ?? 'string') : 'string';

            $display = $this->resolveDisplay($code, $raw, $spec, $stages, $categories);
            $out[] = [
                'code' => $code,
                'title' => (string) $title,
                'type' => (string) $type,
                'raw' => $raw,
                'display' => $display,
            ];
        }

        return $out;
    }

    /**
     * Список/items для enum-поля (crm.deal.fields: items или list).
     */
    private function getFieldListMap(?array $spec): array
    {
        if (!is_array($spec)) {
            return [];
        }
        $src = $spec['items'] ?? $spec['list'] ?? null;
        return is_array($src) ? $this->buildListMap($src) : [];
    }

    /**
     * @param mixed $raw
     * @param array|null $spec
     * @param array<string, string> $stages
     * @param array<string, string> $categories
     */
    private function resolveDisplay(string $code, $raw, ?array $spec, array $stages, array $categories): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (is_array($raw)) {
            if (empty($raw)) {
                return '';
            }
            $map = $this->getFieldListMap($spec);
            $resolved = [];
            foreach ($raw as $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $s = (string) $v;
                $resolved[] = $map[$s] ?? $s;
            }
            return $resolved !== [] ? implode(', ', $resolved) : json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

        $str = (string) $raw;

        if ($code === 'STAGE_ID') {
            if (isset($stages[$str])) {
                return $stages[$str];
            }
            $suffix = strpos($str, ':') !== false ? substr($str, strpos($str, ':') + 1) : $str;
            return $stages[$suffix] ?? $str;
        }
        if ($code === 'CATEGORY_ID') {
            return $categories[$str] ?? $str;
        }

        $map = $this->getFieldListMap($spec);
        if ($map !== []) {
            return $map[$str] ?? $str;
        }

        return $str;
    }

    /**
     * Ключевые поля сделки — отдельные столбцы.
     * Стадия/воронка: технический ID + расшифровка. Ответственный/кто изменил: ID + «Имя Фамилия».
     *
     * @return array{stage_id: string, stage_title: string, category_id: string, category_title: string, assigned_by_id: string, assigned_by_name: string, modify_by_id: string, modify_by_name: string}
     */
    public function extractKeyFields(array $deal): array
    {
        $meta = $this->loadMeta();
        $stages = $meta['stages'];
        $categories = $meta['categories'];

        $stageId = (string) ($deal['STAGE_ID'] ?? $deal['stageId'] ?? '');
        $stageTitle = $stageId;
        if ($stageId !== '') {
            if (isset($stages[$stageId])) {
                $stageTitle = $stages[$stageId];
            } else {
                $suffix = strpos($stageId, ':') !== false ? substr($stageId, strpos($stageId, ':') + 1) : $stageId;
                $stageTitle = $stages[$suffix] ?? $stageId;
            }
        }

        $categoryId = (string) ($deal['CATEGORY_ID'] ?? $deal['categoryId'] ?? '');
        $categoryTitle = $categoryId !== '' ? ($categories[$categoryId] ?? $categoryId) : '';

        $assignedId = (string) ($deal['ASSIGNED_BY_ID'] ?? $deal['assignedById'] ?? '');
        $modifyId = (string) ($deal['MODIFY_BY_ID'] ?? $deal['modifyById'] ?? '');
        $assignedName = '';
        $modifyName = '';

        if ($this->userResolver !== null) {
            if ($assignedId !== '' && $assignedId !== '0') {
                $assignedName = $this->userResolver->getDisplayName($assignedId);
            }
            if ($modifyId !== '' && $modifyId !== '0') {
                $modifyName = $this->userResolver->getDisplayName($modifyId);
            }
        }

        return [
            'stage_id' => $stageId,
            'stage_title' => $stageTitle,
            'category_id' => $categoryId,
            'category_title' => $categoryTitle,
            'assigned_by_id' => $assignedId,
            'assigned_by_name' => $assignedName,
            'modify_by_id' => $modifyId,
            'modify_by_name' => $modifyName,
        ];
    }

    /**
     * Расшифровка изменения поля для entity_field_changes (пользовательские значения).
     *
     * @param mixed $old
     * @param mixed $new
     * @return array{title: string, old_display: string, new_display: string}
     */
    public function resolveChangeForField(string $code, $old, $new): array
    {
        $meta = $this->loadMeta();
        $fields = $meta['fields'];
        $stages = $meta['stages'];
        $categories = $meta['categories'];
        $spec = $fields[$code] ?? null;
        $title = $this->pickFieldTitle($code, $spec);

        return [
            'title' => (string) $title,
            'old_display' => $this->resolveDisplay($code, $old, $spec, $stages, $categories),
            'new_display' => $this->resolveDisplay($code, $new, $spec, $stages, $categories),
        ];
    }
}
