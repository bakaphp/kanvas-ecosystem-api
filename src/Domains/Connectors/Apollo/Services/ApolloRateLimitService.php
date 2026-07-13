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
    public const int DEFAULT_DAILY_LIMIT = 50000;
    public const int DEFAULT_PER_MINUTE_LIMIT = 100;
    public const int MINUTE_WINDOW = 60;
    private const string DEFAULT_REVALIDATION = '-2 months';

    public function hasReachedDailyLimit(CompanyInterface $company, int $dailyLimit = self::DEFAULT_DAILY_LIMIT): bool
    {
        return $this->dailyTotal($company) >= $dailyLimit;
    }

    public function dailyTotal(CompanyInterface $company): int
    {
        $report = (array) ($company->get(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value) ?? []);
        $today = $report[date('Y-m-d')] ?? [];

        return (int) (is_array($today) ? ($today['total'] ?? 0) : 0);
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

    public function minuteCount(AppInterface $app): int
    {
        return (int) Cache::get($this->minuteKey($app), 0);
    }

    public function hasReachedMinuteLimit(AppInterface $app, int $minuteLimit = self::DEFAULT_PER_MINUTE_LIMIT): bool
    {
        return $this->minuteCount($app) >= $minuteLimit;
    }

    public function recordMinuteHit(AppInterface $app): int
    {
        $key = $this->minuteKey($app);

        // Increment first: on Redis, INCRBY on a missing key recreates it with NO expiration
        // (persists forever). If we branched on Cache::has() and the key expired in the race
        // window between the check and the increment, the counter would come back with no TTL
        // and never reset — pinning the window whenever the count is 1 closes that race.
        $count = (int) Cache::increment($key);

        if ($count === 1) {
            Cache::put($key, 1, self::MINUTE_WINDOW);
        }

        return $count;
    }

    public function resetMinuteWindow(AppInterface $app): void
    {
        Cache::forget($this->minuteKey($app));
    }

    /**
     * Seconds to wait after a hit so the remaining per-minute budget spreads evenly across
     * the rest of the window, instead of bursting then stalling at the cap.
     */
    public function pacingDelay(int $currentCount, int $minuteLimit = self::DEFAULT_PER_MINUTE_LIMIT): int
    {
        $remaining = $minuteLimit - $currentCount;

        return $remaining > 0 ? intdiv(self::MINUTE_WINDOW, $remaining) : 2;
    }

    private function minuteKey(AppInterface $app): string
    {
        return 'api_minute_rate_limit_' . (int) $app->getId();
    }
}
