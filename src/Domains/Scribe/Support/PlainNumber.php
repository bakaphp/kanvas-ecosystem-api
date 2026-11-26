<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Support;

/**
 * A quantity or price as plain text for a message or a comparison: no thousands separator and no trailing zeros,
 * so 10.000000 reads 10 and 12.500000 reads 12.5.
 */
final class PlainNumber
{
    public static function format(float $value, int $decimals = 6): string
    {
        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    }
}
