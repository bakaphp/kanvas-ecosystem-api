<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Plans;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\ChaseStaleIntakeAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * Chases every unanswered intake, and drops the ones nobody is coming back to.
 *
 * The half of "an incomplete plan must not sit there" that a status guard cannot do: the guard stops
 * it being executed, this stops it being forgotten.
 */
class SweepStaleIntakeCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:nervous-system:sweep-stale-intake '
        . '{--hours=24 : How long an intake may go unanswered before it is chased} '
        . '{--app= : Restrict to one app id} '
        . '{--force : Chase now, ignoring the one-per-window guard}';

    protected $description = 'Chase unanswered intake plans, and cancel the ones that stay unanswered.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $force = (bool) $this->option('force');

        $plans = Plan::query()
            ->where('status', PlanStatusEnum::INTAKE->value)
            ->where('is_deleted', 0)
            ->when($this->option('app') !== null, fn ($query) => $query->where('apps_id', (int) $this->option('app')))
            ->get();

        $tally = [];

        foreach ($plans as $plan) {
            // Bouncer scope and the container-bound app are process-global on a long-running worker, so
            // a sweep across tenants has to rebind per plan or the second app inherits the first's scope.
            $app = $plan->app;

            if ($app instanceof Apps) {
                $this->overwriteAppService($app);
            }

            $result = new ChaseStaleIntakeAction($plan, $hours, $force)->execute();
            $tally[$result] = ($tally[$result] ?? 0) + 1;
        }

        $this->info(sprintf('Checked %d intake plan(s).', $plans->count()));

        foreach ($tally as $result => $count) {
            $this->line(sprintf('  %-18s %d', $result, $count));
        }

        return self::SUCCESS;
    }
}
