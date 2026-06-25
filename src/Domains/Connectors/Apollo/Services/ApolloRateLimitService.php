<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;

class ApolloRateLimitService
{
    public const int DEFAULT_DAILY_LIMIT = 2000;
    public const int DEFAULT_HOURLY_LIMIT = 400;
    public const int HOURLY_WINDOW = 3600;
    private const string DEFAULT_REVALIDATION = '-2 months';

    public function hasReachedDailyLimit(CompanyInterface $company, int $dailyLimit = self::DEFAULT_DAILY_LIMIT): bool
    {
        return $this->dailyTotal($company) >= $dailyLimit;
    }

    public function dailyTotal(CompanyInterface $company): int
    {
        $report = (array) ($company->get(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value) ?? []);
        $today = $report[date('Y-m-d')] ?? [];

        return (int) ((is_array($today) ? $today['total'] : null) ?? 0);
    }

    public function hasBeenScreenedRecently(People $people): bool
    {
        $lastScreenedAt = $people->get(ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value);
        if (empty($lastScreenedAt)) {
            return false;
        }

        $threshold = $people->company->get(ConfigurationEnum::APOLLO_REVALIDATION->value) ?? self::DEFAULT_REVALIDATION;

        return (int) $lastScreenedAt > strtotime($threshold);
    }

    public function hourlyCount(AppInterface $app): int
    {
        return (int) Cache::get($this->hourlyKey($app), 0);
    }

    public function hasReachedHourlyLimit(AppInterface $app, int $hourlyLimit = self::DEFAULT_HOURLY_LIMIT): bool
    {
        return $this->hourlyCount($app) >= $hourlyLimit;
    }

    public function recordHourlyHit(AppInterface $app): int
    {
        $key = $this->hourlyKey($app);

        // Pin the TTL on the first hit so the window is fixed from then — increment keeps
        // the existing TTL, so the counter resets exactly one hour after it started.
        if (! Cache::has($key)) {
            Cache::put($key, 1, self::HOURLY_WINDOW);

            return 1;
        }

        return (int) Cache::increment($key);
    }

    /**
     * Seconds to wait after a hit so the remaining hourly budget spreads evenly across
     * the rest of the window, instead of bursting then stalling at the cap.
     */
    public function pacingDelay(int $currentCount, int $hourlyLimit = self::DEFAULT_HOURLY_LIMIT): int
    {
        $remaining = $hourlyLimit - $currentCount;

        return $remaining > 0 ? intdiv(self::HOURLY_WINDOW, $remaining) : 2;
    }

    private function hourlyKey(AppInterface $app): string
    {
        return 'api_hourly_rate_limit_' . (int) $app->getId();
    }
}
