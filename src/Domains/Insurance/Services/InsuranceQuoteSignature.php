<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

class InsuranceQuoteSignature
{
    public static function sign(
        int $userId,
        int $planId,
        string $provider,
        string $quoteNumber,
        float $total,
        float $tax,
        int $vehicleVariantId = 0,
    ): string {
        return hash_hmac(
            'sha256',
            implode('|', [
                $userId,
                $planId,
                $provider,
                $quoteNumber,
                number_format($total, 4, '.', ''),
                number_format($tax, 4, '.', ''),
                $vehicleVariantId,
            ]),
            (string) config('app.key')
        );
    }

    public static function matches(
        string $signature,
        int $userId,
        int $planId,
        string $provider,
        string $quoteNumber,
        float $total,
        float $tax,
        int $vehicleVariantId = 0,
    ): bool {
        return $signature !== '' && hash_equals(
            self::sign(
                userId: $userId,
                planId: $planId,
                provider: $provider,
                quoteNumber: $quoteNumber,
                total: $total,
                tax: $tax,
                vehicleVariantId: $vehicleVariantId,
            ),
            $signature
        );
    }
}
