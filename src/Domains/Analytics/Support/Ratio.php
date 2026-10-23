<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Support;

final class Ratio
{
    /**
     * Null, not zero, when there is nothing to divide by: "0 of 0" is no rate at all.
     */
    public static function of(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole, 4) : null;
    }
}
