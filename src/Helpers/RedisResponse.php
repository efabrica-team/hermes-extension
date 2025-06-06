<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Helpers;

final class RedisResponse
{
    /**
     * @param array<int, string|float|int|bool|null> $listResponse
     * @return array<string, string|float|int|bool|null>
     */
    public static function readRedisListResponseToArray(array $listResponse): array
    {
        $output = [];
        for ($i = 0; $i < count($listResponse); $i += 2) {
            $key = $listResponse[$i];
            $value = self::decodeValue($listResponse[$i + 1]);
            $output[$key] = $value;
        }
        return $output;
    }

    /**
     * @param mixed $value
     * @return float|int|mixed
     */
    private static function decodeValue($value)
    {
        if (is_string($value)) {
            if (is_numeric($value)) {
                $filtered = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                if ($filtered !== null) {
                    return $filtered;
                }
                $filtered = filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
                if ($filtered !== null) {
                    return $filtered;
                }
            }
        }
        return $value;
    }
}
