<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\NudgeInactivePlanAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Services\StalePlanNudgeService;

/**
 * Daily sweep: for every open plan that's had no activity (message, task move, plan change) in longer
 * than --hours, nudge the responsible party — @mention a human owner, re-wake (then escalate) a stalled
 * agent, or ping the project owner for unassigned work — and notify the project's human PM. Silence on
 * committed work almost always means something's wrong; this makes sure a person finds out.
 */
class NudgeInactivePlansCommand extends Command
{
    use KanvasJobsTrait;

    private const int DEFAULT_INACTIVITY_HOURS = 24;

    protected $signature = 'kanvas:nervous-system:nudge-inactive-plans
        {--hours= : Inactivity threshold in hours (default 24)}
        {--project= : Only this project id}
        {--force : Nudge even if this plan was already nudged inside the current window}';

    protected $description = 'Ping owners of open plans that have had no activity past the inactivity threshold.';

    public function handle(StalePlanNudgeService $service): int
    {
        $hours = $this->option('hours') !== null ? (int) $this->option('hours') : self::DEFAULT_INACTIVITY_HOURS;
        $projectId = $this->option('project') !== null ? (int) $this->option('project') : null;
        $force = (bool) $this->option('force');

        $nudged = 0;

        foreach ($this->targetAppIds($projectId) as $appId) {
            /** @var Apps $app */
            $app = Apps::getById($appId);

            // Per-app scope rebind — the nudge posts comments and resolves channel/role state; a leaked
            // Bouncer scope from the previous app would throw or cross tenants.
            $this->overwriteAppService($app);

            foreach ($service->stalePlans($app, $hours, $projectId) as $plan) {
                $result = new NudgeInactivePlanAction($plan, $hours, force: $force)->execute();

                if ($result !== NudgeInactivePlanAction::RESULT_SKIPPED) {
                    $nudged++;
                }

                if ($projectId !== null || $force) {
                    $this->line(sprintf('  plan %d "%s" → %s', $plan->getId(), $plan->title, $result));
                }
            }
        }

        $this->info(sprintf('Inactive-plan sweep (>%dh) nudged %d plan(s).', $hours, $nudged));

        return self::SUCCESS;
    }

    /**
     * Apps that own an open, project-bound plan — so we only rebind scope for apps with candidates.
     *
     * @return Collection<int, int>
     */
    private function targetAppIds(?int $projectId): Collection
    {
        $statuses = array_map(
            fn (PlanStatusEnum $status): string => $status->value,
            PlanStatusEnum::openStatuses(),
        );

        return Plan::query()
            ->notDeleted()
            ->whereNotNull('project_id')
            ->whereIn('status', $statuses)
            ->when(
                $projectId !== null,
                fn (Builder $query): Builder => $query->where('project_id', $projectId),
            )
            ->distinct()
            ->pluck('apps_id');
    }
}
