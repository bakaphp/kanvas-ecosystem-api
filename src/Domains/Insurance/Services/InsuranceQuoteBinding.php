<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Illuminate\Support\Facades\Cache;

class InsuranceQuoteBinding
{
    public static function bind(int $appId, int $userId, string $quoteNumber, int $planId): void
    {
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

    private static function seenKey(int $appId, int $userId, string $quoteNumber): string
    {
        return sprintf('insuranceQuoteBindingSeen-%d-%d-%s', $appId, $userId, md5($quoteNumber));
    }

    private static function key(int $appId, int $userId, string $quoteNumber): string
    {
        return sprintf('insuranceQuoteBinding-%d-%d-%s', $appId, $userId, md5($quoteNumber));
    }
}
