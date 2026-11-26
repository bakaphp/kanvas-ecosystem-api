<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Number;
use InvalidArgumentException;

/** A money amount written out for a printed document. */
final class AmountInWords
{
    private const int LIMIT = 1_000_000_000_000;

    /** "mil doscientos cincuenta con 50/100": the whole part in Spanish words, the cents as a fraction of 100. */
    public static function spanish(float $amount): string
    {
        $cents = (int) round($amount * 100);

        if ($cents < 0 || intdiv($cents, 100) >= self::LIMIT) {
            throw new InvalidArgumentException(
                "Cannot write {$amount} in words: it must be between 0 and 999,999,999,999.99."
            );
        }

        $fraction = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
        // ICU marks where a long Spanish word may break with a soft hyphen, which a PDF prints as a stray dash.
        $words = str_replace("\u{00AD}", '', Number::spell(intdiv($cents, 100), 'es'));

        return "{$words} con {$fraction}/100";
    }
}
