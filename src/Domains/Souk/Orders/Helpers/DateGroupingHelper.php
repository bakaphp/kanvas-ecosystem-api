<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Helpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Souk\Orders\Enums\DateGroupByEnum;

class DateGroupingHelper
{
    /**
     * Aggregate ordersInPeriod daily data into week/month buckets.
     * Returns the same array shape as the input for backward compatibility.
     */
    public static function groupOrdersInPeriod(array $periodData, DateGroupByEnum $groupBy, string $timezone): array
    {
        $data = collect($periodData['data'])->groupBy(
            fn ($item) => self::getPeriodKey($item['date'], $groupBy, $timezone)
        );

        $grouped = $data->map(function (Collection $items, string $periodKey) {
            $totalCount = $items->sum('count');

            $mergedStates = [];
            foreach ($items as $item) {
                foreach ($item['states'] as $stateEntry) {
                    $slug = $stateEntry['state'] ?? 'Unknown';
                    $mergedStates[$slug] = ($mergedStates[$slug] ?? 0) + $stateEntry['count'];
                }
            }

            return [
                'date' => $periodKey,
                'count' => $totalCount,
                'states' => collect($mergedStates)->map(fn (int $count, string $state) => [
                    'state' => $state,
                    'count' => $count,
                ])->values()->toArray(),
            ];
        })->values();

        $totalCount = $grouped->sum('count');
        $periodCount = $grouped->count();
        $maxPeriod = $grouped->sortByDesc('count')->first();
        $minPeriod = $grouped->sortBy('count')->first();

        return [
            'orderAvg' => $periodCount > 0 ? $totalCount / $periodCount : 0,
            'maxOrdersDate' => [
                'date' => $maxPeriod['date'] ?? null,
                'count' => $maxPeriod['count'] ?? 0,
            ],
            'minOrdersDate' => [
                'date' => $minPeriod['date'] ?? null,
                'count' => $minPeriod['count'] ?? 0,
            ],
            'data' => $grouped->toArray(),
        ];
    }

    /**
     * Aggregate dailyTurnover data into week/month buckets.
     * Returns the same array shape as the input for backward compatibility.
     */
    public static function groupTurnoverData(array $turnoverData, DateGroupByEnum $groupBy, string $timezone): array
    {
        $data = collect($turnoverData['data'])->groupBy(
            fn ($item) => self::getPeriodKey($item['date'], $groupBy, $timezone)
        );

        $grouped = $data->map(fn (Collection $items, string $periodKey) => [
            'date' => $periodKey,
            'entries' => $items->sum('entries'),
            'exits' => $items->sum('exits'),
        ])->values();

        $totalEntries = $grouped->sum('entries');
        $totalExits = $grouped->sum('exits');
        $periodCount = $grouped->count();

        $maxExit = $grouped->sortByDesc('exits')->first();
        $maxEntry = $grouped->sortByDesc('entries')->first();

        return [
            'totalEntries' => $totalEntries,
            'totalExits' => $totalExits,
            'exitAvg' => $periodCount > 0 ? $totalExits / $periodCount : 0,
            'entryAvg' => $periodCount > 0 ? $totalEntries / $periodCount : 0,
            'exitPercentage' => $totalEntries > 0 ? ($totalExits / $totalEntries * 100) : 0,
            'maxExitDate' => [
                'date' => $maxExit['date'] ?? null,
                'count' => $maxExit['exits'] ?? 0,
            ],
            'maxEntryDate' => [
                'date' => $maxEntry['date'] ?? null,
                'count' => $maxEntry['entries'] ?? 0,
            ],
            'data' => $grouped->toArray(),
        ];
    }

    /**
     * Get the period start date for a given date.
     *
     * DAY   → same date
     * WEEK  → Monday of the ISO week
     * MONTH → 1st of the month
     */
    private static function getPeriodKey(string $date, DateGroupByEnum $groupBy, string $timezone): string
    {
        $carbon = Carbon::parse($date, $timezone);

        return match ($groupBy) {
            DateGroupByEnum::DAY => $carbon->format('Y-m-d'),
            DateGroupByEnum::WEEK => $carbon->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
            DateGroupByEnum::MONTH => $carbon->startOfMonth()->format('Y-m-d'),
        };
    }
}
