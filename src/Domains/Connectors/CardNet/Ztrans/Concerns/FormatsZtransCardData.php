<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\Concerns;

trait FormatsZtransCardData
{
    protected function expirationParts(string $expiration): array
    {
        $digits = preg_replace('/\D+/', '', $expiration) ?? '';

        return [
            'month' => substr($digits, 0, 2),
            'year' => substr($digits, -2),
        ];
    }

    protected function ztransExpiration(string $expiration): string
    {
        ['month' => $month, 'year' => $year] = $this->expirationParts($expiration);

        return $month . '/' . $year;
    }

    protected function threeDSExpiration(string $expiration): string
    {
        ['month' => $month, 'year' => $year] = $this->expirationParts($expiration);

        return $year . $month;
    }

    protected function minorUnits(float $amount): string
    {
        return (string) (int) round($amount * 100);
    }
}
