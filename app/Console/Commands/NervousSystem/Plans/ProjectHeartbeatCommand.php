<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Plans;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Project\Services\ProjectHeartbeatService;

/**
 * The heartbeat: runs every 5 min, and for each open project whose per-project cadence
 * (heartbeat_interval_minutes) has elapsed, wakes its PM if there's stalled or waiting work. This is
 * what keeps in-process (Neuron/Laravel) PMs advancing work when nothing external fired.
 */
class ProjectHeartbeatCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:nervous-system:project-heartbeat
        {--force : Ignore the per-project cadence AND the "needs attention" gate — wake every matched open project now (manual/testing trigger)}
        {--project= : Only evaluate this project id}';

    protected $description = 'Wake project PM agents that have stalled or waiting work, on each project\'s cadence.';

    public function handle(ProjectHeartbeatService $service): int
    {
        $force = (bool) $this->option('force');
        $projectId = $this->option('project') !== null ? (int) $this->option('project') : null;

        $appIds = $service->candidateAppIds(ignoreCadence: $force, projectId: $projectId);

        foreach ($appIds as $appId) {
            /** @var Apps $app */
            $app = Apps::getById($appId);

            // Per-app scope rebind — else the wake's agent/channel Role lookups run under a leaked
            // Bouncer scope from the previous app (the SendDailyLearningDigest incident).
            $this->overwriteAppService($app);

            foreach ($service->dueProjects($app, ignoreCadence: $force, projectId: $projectId) as $project) {
                $woke = $service->tick($project, forceWake: $force);

                if ($force || $projectId !== null) {
                    $this->line(sprintf(
                        '  project %d "%s" → %s',
                        $project->getId(),
                        $project->title,
                        $woke ? 'WOKE PM (dispatched heartbeat wake)' : 'no attention needed — skipped',
                    ));
                }
            }
        }

        $this->info('Project heartbeat processed ' . $appIds->count() . ' app(s).');

        return self::SUCCESS;
    }
}
