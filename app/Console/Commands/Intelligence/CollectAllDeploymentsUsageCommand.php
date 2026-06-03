<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * Collect token usage snapshots from all running agent deployments across all apps.
 *
 * Calls the provider's collectUsage() for each running deployment, which SSHs
 * into the container and runs the runtime CLI usage command, then persists the
 * result as an AgentUsageSnapshot row (upserted per deployment + date).
 *
 * Skipped when OTEL_ENABLED=true — token usage is then collected via the
 * OTel Collector → ingestAgentTelemetry pipeline.
 *
 * Usage:
 *   php artisan kanvas:collect-all-deployments-usage
 *   php artisan kanvas:collect-all-deployments-usage --deployment=42
 *   php artisan kanvas:collect-all-deployments-usage --dry-run
 */
class CollectAllDeploymentsUsageCommand extends Command
{
    protected $signature = 'kanvas:collect-all-deployments-usage
                            {--deployment= : Collect for a single deployment ID only}
                            {--dry-run     : Log what would be collected without persisting}';

    protected $description = 'Collect token usage snapshots from all running agent deployments';

    public function handle(): int
    {
        if (config('otel.enabled', false)) {
            $this->info('OTel is active — token usage is collected via the collector pipeline. Skipping SSH poll.');

            return self::SUCCESS;
        }

        $specificId = $this->option('deployment');
        $dryRun = (bool) $this->option('dry-run');

        $query = AgentDeployment::where('status', 'running')
            ->where('is_deleted', 0)
            ->with(['machine', 'agent']);

        if ($specificId !== null) {
            $query->where('id', (int) $specificId);
        }

        $deployments = $query->get();

        if ($deployments->isEmpty()) {
            $this->info('No running deployments found.');

            return self::SUCCESS;
        }

        $this->info("Found {$deployments->count()} running deployment(s). Collecting usage...");

        $succeeded = 0;
        $failed = 0;

        foreach ($deployments as $deployment) {
            /** @var AgentDeployment $deployment */
            $label = "deployment #{$deployment->getId()} (container: {$deployment->container_name}, app: {$deployment->apps_id})";

            if ($dryRun) {
                $this->line("  [dry-run] Would collect: {$label}");
                $succeeded++;

                continue;
            }

            try {
                $app = Apps::getById($deployment->apps_id);
                $company = Companies::getById($deployment->companies_id);
                $provider = AgentRuntimeProviderFactory::forDeployment($deployment);

                $snapshot = $provider->collectUsage($deployment, $app, $company);

                $this->info(
                    "  ✓ {$label}"
                    . " → snapshot #{$snapshot->getId()}"
                    . " | total_tokens={$snapshot->total_tokens}"
                    . " | in={$snapshot->input_tokens} out={$snapshot->output_tokens}"
                );

                $succeeded++;
            } catch (Throwable $e) {
                $this->error("  ✗ {$label}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done — succeeded: {$succeeded}, failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
