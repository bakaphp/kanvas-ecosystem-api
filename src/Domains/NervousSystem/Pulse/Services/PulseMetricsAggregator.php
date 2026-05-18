<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Pulse\DataTransferObject\PulseAggregateRow;

/**
 * Computes a single window of Pulse metrics live from raw ledger events
 * and plans. Used both by the live-read path (today's window) and by
 * the rollup writer (yesterday's snapshot) — same code → same numbers.
 *
 * Two SQL queries per window:
 *   1. nervous_system_events grouped by category (counts the 5 buckets)
 *   2. nervous_system_plans aggregating prevented_issues + confidence
 */
class PulseMetricsAggregator
{
    public function aggregate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): PulseAggregateRow {
        $eventCounts = $this->aggregateEvents($app, $company, $start, $end);
        $planStats = $this->aggregatePlans($app, $company, $start, $end);

        return new PulseAggregateRow(
            signals_count: (int) ($eventCounts['SIGNAL'] ?? 0),
            understand_count: (int) ($eventCounts['UNDERSTAND'] ?? 0),
            decide_count: (int) ($eventCounts['DECIDE'] ?? 0),
            actions_executed: (int) ($eventCounts['ACT'] ?? 0),
            warnings_count: (int) ($eventCounts['WARNING'] ?? 0),
            prevented_issues: $planStats['prevented_issues'],
            system_confidence_pct: $planStats['confidence_pct'],
            confidence_basis_count: $planStats['confidence_basis'],
        );
    }

    /**
     * Group counts by the now-materialized category column. Uses the
     * existing apps_company_occurred index for the WHERE; grouping in
     * memory is cheap on the bounded result set.
     *
     * @return array<string, int> keyed by category value
     */
    private function aggregateEvents(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): array {
        $rows = DB::connection('intelligence')
            ->table('nervous_system_events')
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereNotNull('category')
            ->whereBetween('occurred_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->select('category', DB::raw('COUNT(*) AS c'))
            ->groupBy('category')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->category] = (int) $row->c;
        }

        return $out;
    }

    /**
     * Prevented issues + confidence average from the plans table for
     * the same window. Uses completed_at to bound the period.
     *
     * @return array{prevented_issues: int, confidence_pct: float|null, confidence_basis: int}
     */
    private function aggregatePlans(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): array {
        $row = DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->whereBetween('completed_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->selectRaw('
                SUM(CASE WHEN error_message IS NOT NULL THEN 1 ELSE 0 END) AS prevented_issues,
                AVG(CAST(confidence_score AS DECIMAL(4,3))) AS avg_confidence,
                COUNT(CASE WHEN confidence_score IS NOT NULL THEN 1 END) AS confidence_basis
            ')
            ->first();

        $confidence = $row?->avg_confidence !== null ? (float) $row->avg_confidence * 100 : null;

        return [
            'prevented_issues' => (int) ($row->prevented_issues ?? 0),
            'confidence_pct' => $confidence,
            'confidence_basis' => (int) ($row->confidence_basis ?? 0),
        ];
    }
}
