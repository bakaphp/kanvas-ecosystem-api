<?php

declare(strict_types=1);

namespace Baka\Support;

use InvalidArgumentException;

/** A money amount written out for a printed document, without `intl`, which this image lacks. */
final class AmountInWords
{
    private const array UNITS = [
        'cero',
        'uno',
        'dos',
        'tres',
        'cuatro',
        'cinco',
        'seis',
        'siete',
        'ocho',
        'nueve',
        'diez',
        'once',
        'doce',
        'trece',
        'catorce',
        'quince',
        'dieciséis',
        'diecisiete',
        'dieciocho',
        'diecinueve',
        'veinte',
        'veintiuno',
        'veintidós',
        'veintitrés',
        'veinticuatro',
        'veinticinco',
        'veintiséis',
        'veintisiete',
        'veintiocho',
        'veintinueve',
    ];

    private const array TENS = [
        3 => 'treinta',
        4 => 'cuarenta',
        5 => 'cincuenta',
        6 => 'sesenta',
        7 => 'setenta',
        8 => 'ochenta',
        9 => 'noventa',
    ];

    private const array HUNDREDS = [
        1 => 'ciento',
        2 => 'doscientos',
        3 => 'trescientos',
        4 => 'cuatrocientos',
        5 => 'quinientos',
        6 => 'seiscientos',
        7 => 'setecientos',
        8 => 'ochocientos',
        9 => 'novecientos',
    ];

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

        return self::words(intdiv($cents, 100)) . " con {$fraction}/100";
    }

    private static function words(int $number): string
    {
        if ($number < 30) {
            return self::UNITS[$number];
        }

        if ($number < 100) {
            $units = $number % 10;

            return self::TENS[intdiv($number, 10)] . ($units === 0 ? '' : ' y ' . self::UNITS[$units]);
        }

        if ($number < 1000) {
            return self::hundreds($number);
        }

        if ($number < 1_000_000) {
            return self::scaled(
                intdiv($number, 1000),
                'mil',
                'mil',
                $number % 1000
            );
        }

        return self::scaled(
            intdiv($number, 1_000_000),
            'millón',
            'millones',
            $number % 1_000_000
        );
    }

    private static function hundreds(int $number): string
    {
        if ($number === 100) {
            return 'cien';
        }

        $rest = $number % 100;

        return self::HUNDREDS[intdiv($number, 100)] . ($rest === 0 ? '' : ' ' . self::words($rest));
    }

    /** "un millón", "veintiún mil", "dos millones": the multiplier is "un" before the noun, and "mil" never takes one. */
    private static function scaled(
        int $count,
        string $singular,
        string $plural,
        int $remainder,
    ): string {
        $head = match (true) {
            $count === 1 && $singular === 'mil' => 'mil',
            $count === 1 => "un {$singular}",
            default => self::beforeNoun(self::words($count)) . ' ' . $plural,
        };

        return $remainder === 0 ? $head : $head . ' ' . self::words($remainder);
    }

    private static function beforeNoun(string $words): string
    {
        return (string) preg_replace(['/veintiuno$/', '/uno$/'], ['veintiún', 'un'], $words);
    }
}
