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
    }

    public static function planIdFor(int $appId, int $userId, string $quoteNumber): ?int
    {
        $planId = Cache::get(self::key($appId, $userId, $quoteNumber));

        return $planId === null ? null : (int) $planId;
    }

    private static function key(int $appId, int $userId, string $quoteNumber): string
    {
        return sprintf('insuranceQuoteBinding-%d-%d-%s', $appId, $userId, md5($quoteNumber));
    }
}
