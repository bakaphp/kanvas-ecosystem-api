<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * Provider-routed usage collection for every running container-runtime deployment.
 *
 * Replaces the OpenClaw-only kanvas:openclaw-collect-deployment-usage, which
 * hardcoded the OpenClaw action and silently never collected Hermes. Routing
 * through AgentRuntimeProviderFactory::forDeployment dispatches each deployment
 * to its own runtime's collectUsage() (OpenClaw status --usage, Hermes sessions
 * DB), so all container runtimes land in agent_usage_snapshots.
 *
 * In-process backends (Neuron, Laravel) have no deployment row and are handled
 * by RollupLocalAgentUsageCommand instead.
 */
class CollectAgentDeploymentUsageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-intelligence:collect-deployment-usage
                            {--deployment= : Run for a single deployment id (skip the iteration)}';

    protected $description = 'Collect token/cost usage from every running container-runtime deployment (OpenClaw, Hermes) into agent_usage_snapshots.';

    public function handle(): int
    {
        $deploymentRaw = $this->option('deployment');

        $query = AgentDeployment::query()
            ->where('status', DeploymentStatusEnum::RUNNING->value)
            ->where('is_deleted', 0);

        if (is_string($deploymentRaw) && $deploymentRaw !== '') {
            $query->where('id', (int) $deploymentRaw);
        } else {
            // Only runtimes that implement collectUsage(). Widen as others adopt it.
            $query->whereIn('provider', [
                AgentProviderEnum::HERMES->value,
                AgentProviderEnum::OPENCLAW->value,
            ]);
        }

        $deployments = $query->get();
        $successCount = 0;
        $failureCount = 0;

        foreach ($deployments as $deployment) {
            try {
                $company = $deployment->company;
                $app = $deployment->app;
                if ($company === null || $app === null) {
                    $this->warn("Skipping deployment {$deployment->id} — missing app/company");

                    continue;
                }

                $this->overwriteAppService($app);

                $snapshot = AgentRuntimeProviderFactory::forDeployment($deployment)
                    ->collectUsage($deployment, $app, $company);

                $successCount++;
                $this->line(sprintf(
                    '[%d] %s: %d tokens, $%s → snapshot #%d',
                    $deployment->id,
                    $deployment->provider ?? '?',
                    $snapshot->total_tokens,
                    number_format((float) $snapshot->cost_usd, 4),
                    $snapshot->getId(),
                ));
            } catch (Throwable $e) {
                $failureCount++;
                Log::error('CollectAgentDeploymentUsageCommand: deployment failed', [
                    'deployment_id' => $deployment->id,
                    'provider' => $deployment->provider,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('[%d] FAILED: %s', $deployment->id, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'Done: %d deployments, %d ok, %d failed',
            $deployments->count(),
            $successCount,
            $failureCount,
        ));

        return $failureCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
