<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Support\Number;

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
        string $paymentTerm = '',
    ): string {
        $parts = [
            $userId,
            $planId,
            $provider,
            $quoteNumber,
            Number::fixed($total, 4),
            Number::fixed($tax, 4),
            $vehicleVariantId,
        ];

        if ($paymentTerm !== '') {
            $parts[] = $paymentTerm;
        }

        return hash_hmac('sha256', implode('|', $parts), (string) config('app.key'));
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
        string $paymentTerm = '',
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
                paymentTerm: $paymentTerm,
            ),
            $signature
        );
    }
}
