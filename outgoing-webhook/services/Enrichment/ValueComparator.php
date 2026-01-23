<?php
declare(strict_types=1);

class ValueComparator
{
    public static function sortRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
            ksort($value);
        } else {
            sort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }

        return $value;
    }

    public static function valuesEqual($left, $right): bool
    {
        return json_encode(self::sortRecursive($left)) === json_encode(self::sortRecursive($right));
    }
}
