<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Support\Facades\Date;

/**
 * Shared timeframe parsing for reporting tools. Turns a preset (today / yesterday / last_7_days /
 * last_30_days) or an explicit from/to into the AnalyticsRequest arg shape, resolving dates in the
 * company timezone. Requires HasKanvasContext (uses $this->company).
 */
trait ResolvesAnalyticsTimeframe
{
    /**
     * @return array{from: string, to: string, bucket: string, timezone: string}
     */
    protected function analyticsRangeArgs(?string $timeframe, ?string $from, ?string $to): array
    {
        $timezone = (string) ($this->company->timezone ?? '') ?: (string) (config('app.timezone') ?? 'UTC');

        $from = trim((string) $from);
        $to = trim((string) $to);
        if ($from !== '' && $to !== '') {
            return ['from' => $from, 'to' => $to, 'bucket' => 'DAY', 'timezone' => $timezone];
        }

        $now = Date::now($timezone);
        [$start, $end] = match (strtolower(trim((string) $timeframe))) {
            'today' => [$now->copy(), $now->copy()],
            'yesterday' => [$now->copy()->subDay(), $now->copy()->subDay()],
            'last_30_days' => [$now->copy()->subDays(29), $now->copy()],
            default => [$now->copy()->subDays(6), $now->copy()],
        };

        return [
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'bucket' => 'DAY',
            'timezone' => $timezone,
        ];
    }
}
