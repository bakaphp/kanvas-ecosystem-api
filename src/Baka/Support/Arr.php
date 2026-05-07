<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Arr as IlluminateArr;

class Arr extends IlluminateArr
{
    /**
     * Truncate string values recursively in a nested array to fit within a max byte size.
     * Useful for APIs with payload size limits (e.g., OneSignal 2048 bytes, Expo 4KiB).
     */
    public static function truncateToFit(array $data, int $maxBytes = 2048): array
    {
        if (strlen((string) json_encode($data)) <= $maxBytes) {
            return $data;
        }

        foreach ([200, 100, 50] as $maxLength) {
            $data = self::truncateStrings($data, $maxLength);

            if (strlen((string) json_encode($data)) <= $maxBytes) {
                return $data;
            }
        }

        return $data;
    }

    /**
     * Recursively truncate string values in a nested array to a max character length.
     */
    public static function truncateStrings(array $data, int $maxLength): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::truncateStrings($value, $maxLength);
            } elseif (is_string($value) && mb_strlen($value) > $maxLength) {
                $data[$key] = mb_substr($value, 0, $maxLength) . '...';
            }
        }

        return $data;
    }
}
