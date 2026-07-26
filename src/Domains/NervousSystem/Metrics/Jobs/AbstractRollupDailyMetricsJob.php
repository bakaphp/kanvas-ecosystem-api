<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Metrics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Ledger\Enums\LedgerQueueEnum;
use Throwable;

/**
 * Shared cron entry-point shape for the Pulse/Dashboard nightly rollups:
 * find every (apps_id, companies_id) tuple with activity yesterday, roll
 * each up, and log per-tuple failures without stopping the batch — one
 * tenant's problem shouldn't block the rest.
 *
 * Pulse and Dashboard dispatch as two independent jobs on the same
 * queue — separate surfaces, separate schemas, separate rollup logic,
 * separate failure modes. Running them in lockstep would couple them.
 */
abstract class AbstractRollupDailyMetricsJob implements ShouldQueue
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
        $start = $date->copy();
        $end = $date->copy()->endOfDay();

        $tuples = $this->tenantTuplesForDay($start, $end);

        $rolledUp = 0;
        $failed = 0;

        foreach ($tuples as $tuple) {
            try {
                $app = Apps::find($tuple->apps_id);
                $company = Companies::find($tuple->companies_id);

                if ($app === null || $company === null) {
                    continue;
                }

                $this->rollup($app, $company, $date);
                $rolledUp++;
            } catch (Throwable $e) {
                $failed++;
                Log::error($this->logPrefix() . ' rollup failed for tuple', [
                    'apps_id' => $tuple->apps_id,
                    'companies_id' => $tuple->companies_id,
                    'metric_date' => $date->toDateString(),
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        Log::info($this->logPrefix() . ' rollup completed', [
            'metric_date' => $date->toDateString(),
            'tuples_total' => $tuples->count(),
            'rolled_up' => $rolledUp,
            'failed' => $failed,
        ]);
    }

    abstract protected function tenantTuplesForDay(Carbon $start, Carbon $end): Collection;

    abstract protected function rollup(Apps $app, Companies $company, Carbon $date): void;

    abstract protected function logPrefix(): string;
}
