<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Arr as IlluminateArr;

class Arr extends IlluminateArr
{
    /**
     * The key as the array spells it: an exact match, else the first key equal under strcasecmp.
     * External payloads (form builders, Zoho api_names) and the humans mapping them disagree on
     * casing, so a lookup by the caller's spelling misses rows that are there.
     */
    public static function keyIgnoringCase(array $array, string $key): int|string|null
    {
        if (array_key_exists($key, $array)) {
            return $key;
        }

        foreach (array_keys($array) as $candidate) {
            if (strcasecmp((string) $candidate, $key) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Byte size of an array once JSON-encoded. Useful when an API caps payload
     * size in bytes (Algolia 10KB records, OneSignal 2048, Expo 4KiB, ...).
     */
    public static function sizeInBytes(array $data): int
    {
        return strlen((string) json_encode($data));
    }

    /**
     * Truncate string values recursively in a nested array to fit within a max byte size.
     * Useful for APIs with payload size limits (e.g., OneSignal 2048 bytes, Expo 4KiB).
     */
    public static function truncateToFit(array $data, int $maxBytes = 2048): array
    {
        if (self::sizeInBytes($data) <= $maxBytes) {
            return $data;
        }

        foreach ([200, 100, 50] as $maxLength) {
            $data = self::truncateStrings($data, $maxLength);

            if (self::sizeInBytes($data) <= $maxBytes) {
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
