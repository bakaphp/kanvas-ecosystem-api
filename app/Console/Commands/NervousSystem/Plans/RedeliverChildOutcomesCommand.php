<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Plans;

use Illuminate\Console\Command;
use Kanvas\NervousSystem\Plan\Jobs\ResumeParentTaskJob;
use Kanvas\NervousSystem\Plan\Models\TaskChildOutcome;

/**
 * Re-queues the wake for any child outcome that was committed but never delivered — the worker died
 * between the commit and the after-commit dispatch. Pure dispatcher: it reads one table globally and
 * does no app-scoped work, so it intentionally does NOT call overwriteAppService; the job rebinds.
 */
class RedeliverChildOutcomesCommand extends Command
{
    /** Younger than this and the after-commit dispatch may simply not have run yet. */
    public const int UNDELIVERED_AFTER_MINUTES = 2;

    protected $signature = 'kanvas:nervous-system:redeliver-child-outcomes
        {--limit=200 : Max outcomes to re-dispatch per tick}';

    protected $description = 'Re-dispatch the parent wake for child task outcomes whose wake was never queued.';

    public function handle(): int
    {
        $outcomes = TaskChildOutcome::query()
            ->undeliveredFor(self::UNDELIVERED_AFTER_MINUTES)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($outcomes as $outcome) {
            ResumeParentTaskJob::dispatch($outcome);
        }

        $this->info(sprintf('child outcomes: re-dispatched %d undelivered.', $outcomes->count()));

        return self::SUCCESS;
    }
}
