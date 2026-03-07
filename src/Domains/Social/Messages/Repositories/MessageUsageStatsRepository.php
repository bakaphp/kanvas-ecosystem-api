<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class MessageUsageStatsRepository
{
    private const CACHE_TTL_SECONDS = 3600; // 1 hour

    /**
     * Get daily message counts for a user over the last N days.
     */
    public static function getUserDailyStats(
        Apps $app,
        Users $user,
        int $days = 7,
        ?MessageType $messageType = null,
    ): array {
        $cacheKey = self::buildCacheKey(
            'user',
            $app->getId(),
            $user->getId(),
            $days,
            $messageType?->getId(),
        );

        return self::remember($cacheKey, function () use ($app, $user, $days, $messageType) {
            $counts = self::fetchDailyCounts(
                app: $app,
                days: $days,
                usersId: $user->getId(),
                companiesId: null,
                messageType: $messageType,
            );

            return self::buildResult($counts, $days);
        });
    }

    /**
     * Get daily message counts for an entire company over the last N days.
     */
    public static function getCompanyDailyStats(
        Apps $app,
        Companies $company,
        int $days = 7,
        ?MessageType $messageType = null,
    ): array {
        $cacheKey = self::buildCacheKey(
            'company',
            $app->getId(),
            $company->getId(),
            $days,
            $messageType?->getId(),
        );

        return self::remember($cacheKey, function () use ($app, $company, $days, $messageType) {
            $counts = self::fetchDailyCounts(
                app: $app,
                days: $days,
                usersId: null,
                companiesId: $company->getId(),
                messageType: $messageType,
            );

            return self::buildResult($counts, $days);
        });
    }

    /**
     * Invalidate cache for a specific user's stats (call when new messages are created).
     */
    public static function invalidateUserCache(Apps $app, Users $user, ?MessageType $messageType = null): void
    {
        foreach ([7, 14, 30] as $days) {
            Cache::forget(self::buildCacheKey('user', $app->getId(), $user->getId(), $days, $messageType?->getId()));
        }
    }

    /**
     * Invalidate cache for a specific company's stats.
     */
    public static function invalidateCompanyCache(Apps $app, Companies $company, ?MessageType $messageType = null): void
    {
        foreach ([7, 14, 30] as $days) {
            Cache::forget(self::buildCacheKey('company', $app->getId(), $company->getId(), $days, $messageType?->getId()));
        }
    }

    /**
     * Query the messages table for daily counts within the date range.
     */
    private static function fetchDailyCounts(
        Apps $app,
        int $days,
        ?int $usersId,
        ?int $companiesId,
        ?MessageType $messageType,
    ): Collection {
        $start = Carbon::now()->subDays($days - 1)->startOfDay();
        $end = Carbon::now()->endOfDay();

        return Message::query()
            ->selectRaw('COUNT(id) as count, DATE(created_at) as date')
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->when($usersId !== null, fn ($q) => $q->where('users_id', $usersId))
            ->when($companiesId !== null, fn ($q) => $q->where('companies_id', $companiesId))
            ->when($messageType !== null, fn ($q) => $q->where('message_types_id', $messageType->getId()))
            ->whereBetween('created_at', [$start, $end])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->keyBy('date');
    }

    /**
     * Build the final result array, zero-filling days with no messages.
     */
    private static function buildResult(Collection $counts, int $days): array
    {
        $start = Carbon::now()->subDays($days - 1)->startOfDay();
        $end = Carbon::now()->endOfDay();

        $data = [];
        $totalCount = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = Carbon::now()->subDays($days - 1 - $i)->format('Y-m-d');
            $count = (int) ($counts->get($date)?->count ?? 0);
            $totalCount += $count;
            $data[] = [
                'date' => $date,
                'count' => $count,
            ];
        }

        return [
            'period' => [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'days' => $days,
            ],
            'totalCount' => $totalCount,
            'data' => $data,
        ];
    }

    /**
     * Wrap a callable in Cache::remember, bypassing cache in test environment
     * to prevent stale results from polluting test assertions.
     */
    private static function remember(string $key, callable $callback): array
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember($key, self::CACHE_TTL_SECONDS, $callback);
    }

    private static function buildCacheKey(
        string $scope,
        int $appId,
        int $scopeId,
        int $days,
        ?int $messageTypeId,
    ): string {
        return sprintf(
            'message_usage_stats_%s_app_%d_id_%d_days_%d_type_%s',
            $scope,
            $appId,
            $scopeId,
            $days,
            $messageTypeId ?? 'all',
        );
    }
}
