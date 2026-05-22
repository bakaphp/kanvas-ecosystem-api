<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Dashboard\DataTransferObject\DashboardAggregateRow;

/**
 * Computes a single window of metrics live from nervous_system_plans.
 * Used both by the live-read path (today's window) and by the rollup
 * writer (yesterday's window), so the formula is guaranteed identical
 * across both paths.
 *
 * Filter contract for "completed" / "mistakes":
 *   - status='done' AND completed_at IN window  → counted as accomplishment
 *   - error_message IS NOT NULL AND requires_human_approval=0 → auto-corrected
 *   - error_message IS NOT NULL AND requires_human_approval=1 → escalated
 *
 * Output JSON keys read from plans.output:
 *   - output.time_recovered_hours (decimal)
 *   - output.value_delivered_cents (int)
 *   - output.estimated_human_hours (decimal)
 *
 * If no plan in the window populated a key, the corresponding aggregate
 * is null (not 0). This preserves "no data yet" vs "measured zero".
 */
class DashboardMetricsAggregator
{
    public function aggregate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): DashboardAggregateRow {
        $row = DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->whereBetween('completed_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->selectRaw("
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS plans_completed,
                SUM(CASE WHEN error_message IS NOT NULL AND requires_human_approval = 0 THEN 1 ELSE 0 END) AS mistakes_auto_corrected,
                SUM(CASE WHEN error_message IS NOT NULL AND requires_human_approval = 1 THEN 1 ELSE 0 END) AS mistakes_escalated,
                COUNT(DISTINCT agent_id) AS agents_active,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(output, '$.time_recovered_hours')) AS DECIMAL(10,2))) AS time_recovered_hours,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(output, '$.value_delivered_cents')) AS UNSIGNED)) AS value_delivered_cents,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(output, '$.estimated_human_hours')) AS DECIMAL(10,2))) AS estimated_human_hours
            ")
            ->first();

        if ($row === null) {
            return new DashboardAggregateRow();
        }

        return new DashboardAggregateRow(
            plans_completed: (int) ($row->plans_completed ?? 0),
            mistakes_auto_corrected: (int) ($row->mistakes_auto_corrected ?? 0),
            mistakes_escalated: (int) ($row->mistakes_escalated ?? 0),
            agents_active: (int) ($row->agents_active ?? 0),
            time_recovered_hours: $row->time_recovered_hours !== null ? (float) $row->time_recovered_hours : null,
            value_delivered_cents: $row->value_delivered_cents !== null ? (int) $row->value_delivered_cents : null,
            estimated_human_hours: $row->estimated_human_hours !== null ? (float) $row->estimated_human_hours : null,
        );
    }
}
