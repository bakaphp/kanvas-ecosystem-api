<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Illuminate\Support\Facades\Cache;

class InsuranceQuoteBinding
{
    public static function bind(
        int $appId,
        int $userId,
        string $quoteNumber,
        int $planId,
        ?int $vehicleVariantId = null
    ): void {
        if ($vehicleVariantId === null) {
            Cache::forget(self::vehicleKey($appId, $userId, $quoteNumber));
        } else {
            Cache::put(
                self::vehicleKey($appId, $userId, $quoteNumber),
                $vehicleVariantId,
                (int) config('insurance.quote_binding_ttl', 1800)
            );
        }

        Cache::put(
            self::key($appId, $userId, $quoteNumber),
            $planId,
            (int) config('insurance.quote_binding_ttl', 1800)
        );
        Cache::put(
            self::seenKey($appId, $userId, $quoteNumber),
            $planId,
            (int) config('insurance.quote_binding_seen_ttl', 86400)
        );
    }

    public static function wasBound(int $appId, int $userId, string $quoteNumber): bool
    {
        return Cache::has(self::seenKey($appId, $userId, $quoteNumber));
    }

    public static function planIdFor(int $appId, int $userId, string $quoteNumber): ?int
    {
        $planId = Cache::get(self::key($appId, $userId, $quoteNumber));

        return $planId === null ? null : (int) $planId;
    }

    public static function vehicleVariantIdFor(int $appId, int $userId, string $quoteNumber): ?int
    {
        $productId = Cache::get(self::vehicleKey($appId, $userId, $quoteNumber));

        return $productId === null ? null : (int) $productId;
    }

    private static function vehicleKey(int $appId, int $userId, string $quoteNumber): string
    {
        return sprintf('insuranceQuoteBindingVehicle-%d-%d-%s', $appId, $userId, md5($quoteNumber));
    }

    private static function seenKey(int $appId, int $userId, string $quoteNumber): string
    {
        return sprintf('insuranceQuoteBindingSeen-%d-%d-%s', $appId, $userId, md5($quoteNumber));
    }

    private static function key(int $appId, int $userId, string $quoteNumber): string
    {
        return sprintf('insuranceQuoteBinding-%d-%d-%s', $appId, $userId, md5($quoteNumber));
    }
}
