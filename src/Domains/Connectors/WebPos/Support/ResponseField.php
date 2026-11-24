<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Support;

use Baka\Support\Str;

/**
 * WebPOS names the same field differently across endpoints (`feNumber`/`cufe`, `eCF`/`ecf`), so reads take
 * the first non-empty of several keys.
 */
final class ResponseField
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $value = Str::trimToNull((string) $value);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
