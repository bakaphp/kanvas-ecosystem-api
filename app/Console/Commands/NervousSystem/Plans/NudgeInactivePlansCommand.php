<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Plans;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\NudgeInactivePlanAction;
use Kanvas\NervousSystem\Project\Actions\NotifyProjectInactivePlansAction;
use Kanvas\NervousSystem\Project\Services\StalePlanNudgeService;
use Kanvas\NervousSystem\Project\Support\InactivePlanNudge;

/**
 * Daily sweep: for every open plan that's had no activity (message, task move, plan change) in longer
 * than --hours, nudge the responsible party — @mention a human owner, re-wake (then escalate) a stalled
 * agent, or ping the project owner for unassigned work — then mail the project owner one digest per
 * project listing everything that was nudged. Silence on committed work almost always means something's
 * wrong; this makes sure a person finds out.
 */
class NudgeInactivePlansCommand extends Command
{
    use KanvasJobsTrait;

    private const int DEFAULT_INACTIVITY_HOURS = 24;

    private const array UNNUDGED_RESULTS = [
        NudgeInactivePlanAction::RESULT_SKIPPED,
        NudgeInactivePlanAction::RESULT_NO_PROJECT,
    ];

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
        $digests = 0;

        foreach ($service->candidateAppIds($projectId) as $appId) {
            /** @var Apps $app */
            $app = Apps::getById($appId);

            // Per-app scope rebind — the nudge posts comments and resolves channel/role state; a leaked
            // Bouncer scope from the previous app would throw or cross tenants.
            $this->overwriteAppService($app);

            /** @var array<int, list<InactivePlanNudge>> $nudgesByProject */
            $nudgesByProject = [];

            foreach ($service->stalePlans($app, $hours, $projectId) as $plan) {
                $result = new NudgeInactivePlanAction($plan, $hours, force: $force)->execute();

                if (! in_array($result, self::UNNUDGED_RESULTS, true)) {
                    $nudged++;
                    $nudgesByProject[$plan->project_id][] = new InactivePlanNudge($plan, $result);
                }

                if ($projectId !== null || $force) {
                    $this->line(sprintf('  plan %d "%s" → %s', $plan->getId(), $plan->title, $result));
                }
            }

            foreach ($nudgesByProject as $nudges) {
                if (new NotifyProjectInactivePlansAction($nudges[0]->plan->project, $nudges, $hours)->execute()) {
                    $digests++;
                }
            }
        }

        $this->info(sprintf(
            'Inactive-plan sweep (>%dh) nudged %d plan(s), sent %d project digest(s).',
            $hours,
            $nudged,
            $digests,
        ));

        return self::SUCCESS;
    }
}
