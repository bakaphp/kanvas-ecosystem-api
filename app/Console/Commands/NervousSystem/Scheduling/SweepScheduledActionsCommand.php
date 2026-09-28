<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Scheduling;

use Illuminate\Console\Command;
use Kanvas\NervousSystem\Scheduling\Jobs\RunScheduledAgentActionJob;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionSweepService;

/**
 * Runs every minute. Reclaims any rows a crashed worker left stuck, then claims the due batch and
 * dispatches one fire-job per row. Pure dispatcher — it reads its own table globally and does no
 * app-scoped work, so it intentionally does NOT call overwriteAppService (each job rebinds its own
 * scope). The per-app boundary lives in RunScheduledAgentActionJob.
 */
class SweepScheduledActionsCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:sweep-scheduled-actions
        {--limit=500 : Max rows to claim and dispatch per tick}';

    protected $description = 'Dispatch due scheduled agent actions (reminders / agent tasks).';

    public function handle(ScheduledActionSweepService $sweeper): int
    {
        $reclaimed = $sweeper->reclaimStale();

        $due = $sweeper->claimDue((int) $this->option('limit'));

        foreach ($due as $action) {
            RunScheduledAgentActionJob::dispatch(
                $action->app,
                $action->company,
                $action
            );
        }

        $this->info(sprintf(
            'scheduled-actions swept: reclaimed %d stale, dispatched %d due.',
            $reclaimed,
            $due->count(),
        ));

        return self::SUCCESS;
    }
}
