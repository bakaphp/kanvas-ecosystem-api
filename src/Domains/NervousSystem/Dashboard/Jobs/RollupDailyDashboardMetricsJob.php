<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Dashboard\Actions\RollupDashboardMetricsAction;
use Kanvas\NervousSystem\Ledger\Enums\LedgerQueueEnum;
use Throwable;

/**
 * Cron entry point — runs nightly to roll up yesterday for every
 * (apps_id, companies_id) tuple that had any plan activity in the day.
 *
 * Failures on individual tuples are caught and logged so one tenant's
 * problem doesn't block the rest. The reader's snapshot-missing fallback
 * means a failed rollup is not user-facing — it just degrades to live
 * aggregation for that day until the next run succeeds.
 */
class RollupDailyDashboardMetricsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?Carbon $date = null,
    ) {
        $this->onQueue(LedgerQueueEnum::LEDGER->value);
    }

    public function handle(): void
    {
        $date = ($this->date ?? Carbon::yesterday())->copy()->startOfDay();
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $tuples = DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('is_deleted', 0)
            ->whereBetween('completed_at', [$start, $end])
            ->select('apps_id', 'companies_id')
            ->distinct()
            ->get();

        $rolledUp = 0;
        $failed = 0;

        foreach ($tuples as $tuple) {
            try {
                $app = Apps::find($tuple->apps_id);
                $company = Companies::find($tuple->companies_id);

                if ($app === null || $company === null) {
                    continue;
                }

                new RollupDashboardMetricsAction(
                    $app,
                    $company,
                    $date
                )->execute();
                $rolledUp++;
            } catch (Throwable $e) {
                $failed++;

                report($e);
            }
        }
    }
}
