<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Ledger\Enums\LedgerQueueEnum;
use Kanvas\NervousSystem\Pulse\Actions\RollupPulseMetricsAction;
use Throwable;

/**
 * Cron entry — rolls up yesterday's Pulse metrics for every (apps_id,
 * companies_id) tuple that had ledger activity in the day.
 *
 * Independent from the dashboard rollup job: Pulse and Dashboard are
 * separate surfaces with separate schemas, separate rollup logic, and
 * separate failure modes. Running them in lockstep would couple them.
 *
 * Failures on individual tuples are caught + logged so one tenant's
 * problem doesn't stop the rest.
 */
class RollupDailyPulseMetricsJob implements ShouldQueue
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

        // Tenants that had ANY ledger activity in the day — pulse metrics
        // only make sense for tenants where the nervous system actually
        // saw events.
        $tuples = DB::connection('intelligence')
            ->table('nervous_system_events')
            ->whereBetween('occurred_at', [$start, $end])
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

                new RollupPulseMetricsAction($app, $company, $date)->execute();
                $rolledUp++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('[NS:Pulse] rollup failed for tuple', [
                    'apps_id' => $tuple->apps_id,
                    'companies_id' => $tuple->companies_id,
                    'metric_date' => $date->toDateString(),
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        Log::info('[NS:Pulse] rollup completed', [
            'metric_date' => $date->toDateString(),
            'tuples_total' => $tuples->count(),
            'rolled_up' => $rolledUp,
            'failed' => $failed,
        ]);
    }
}
