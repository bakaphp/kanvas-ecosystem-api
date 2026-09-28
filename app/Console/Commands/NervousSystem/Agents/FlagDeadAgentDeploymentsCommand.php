<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * Immediate relief for the recurring health/kanban Sentry noise (KANVAS-ECOSYSTEM-5RP / 5MV / 5BH):
 * flag dead `running` deployments as FAILED so they drop out of every `status = running` query the
 * crons iterate. Use `--machine` for a known-dead host (flags without probing); otherwise probes
 * each deployment once and flags the ones that fail. The regular health cron self-heals the same way
 * over time — this is the one-shot to clear the backlog now.
 */
class FlagDeadAgentDeploymentsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:agent-runtime-flag-dead-deployments
        {--machine= : AgentMachine id — flag ALL its running deployments FAILED without probing (known-dead host)}
        {--app= : Restrict to a single app id}
        {--dry-run : List what would be flagged without changing anything}';

    protected $description = 'Flag dead running agent deployments as failed so they stop being probed and reported';

    public function handle(): int
    {
        $machineId = $this->option('machine') !== null ? (int) $this->option('machine') : null;
        $appId = $this->option('app') !== null ? (int) $this->option('app') : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = AgentDeployment::query()
            ->where('status', DeploymentStatusEnum::RUNNING->value)
            ->notDeleted();

        if ($machineId !== null) {
            $query->where('agent_machine_id', $machineId);
        }

        if ($appId !== null) {
            $query->where('apps_id', $appId);
        }

        $flagged = 0;
        $skipped = 0;

        $query->chunkById(50, function ($deployments) use ($machineId, $dryRun, &$flagged, &$skipped): void {
            foreach ($deployments as $deployment) {
                /** @var Apps $app */
                $app = Apps::getById($deployment->apps_id);
                $this->overwriteAppService($app);

                // A named machine is asserted dead by the operator — flag without a slow SSH probe.
                $dead = $machineId !== null ? true : $this->isDead($deployment);

                if (! $dead) {
                    $skipped++;

                    continue;
                }

                $this->line(sprintf(
                    '  deployment=%-5d %-30s → %s',
                    $deployment->getId(),
                    substr((string) $deployment->container_name, 0, 30),
                    $dryRun ? 'would flag failed' : 'flagged failed',
                ));

                if ($dryRun) {
                    $flagged++;

                    continue;
                }

                $deployment->status = DeploymentStatusEnum::FAILED->value;
                $deployment->error_message = 'Flagged dead by kanvas:agent-runtime-flag-dead-deployments';
                $deployment->saveOrFail();
                $flagged++;
            }
        });

        $this->info(sprintf(
            '%s: flagged=%d skipped(healthy)=%d',
            $dryRun ? 'Dry run' : 'Done',
            $flagged,
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function isDead(AgentDeployment $deployment): bool
    {
        try {
            return AgentRuntimeProviderFactory::forDeployment($deployment)->checkHealth($deployment)
                === HealthCheckResultEnum::FAILED;
        } catch (Throwable) {
            // Orphaned agent / unresolvable provider — can never succeed, so it's dead.
            return true;
        }
    }
}
