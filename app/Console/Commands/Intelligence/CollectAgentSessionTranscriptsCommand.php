<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * Inline iteration (not per-deployment job) for v1 — one slow box doesn't
 * block the rest because each call is try/catch'd. Fan out to the
 * agent-runtime queue if/when serial latency becomes a problem.
 */
class CollectAgentSessionTranscriptsCommand extends Command
{
    protected $signature = 'kanvas-intelligence:collect-session-transcripts
                            {--deployment= : Run for a single deployment id (skip the iteration)}
                            {--since= : ISO-8601 lookback floor (overrides the per-conversation watermark)}';

    protected $description = 'Pull conversation transcripts from every running container-runtime deployment into agent_conversations/agent_conversation_messages.';

    public function handle(): int
    {
        $app = app(Apps::class);

        $sinceRaw = $this->option('since');
        $since = is_string($sinceRaw) && $sinceRaw !== '' ? Carbon::parse($sinceRaw) : null;

        $deploymentRaw = $this->option('deployment');

        $query = AgentDeployment::query()
            ->where('status', DeploymentStatusEnum::RUNNING->value)
            ->where('is_deleted', 0);

        if (is_string($deploymentRaw) && $deploymentRaw !== '') {
            $query->where('id', (int) $deploymentRaw);
        } else {
            // Widen this list as other runtimes implement collectSessionTranscripts().
            $query->where('provider', AgentProviderEnum::HERMES->value);
        }

        $deployments = $query->get();
        $totalPersisted = 0;
        $successCount = 0;
        $failureCount = 0;

        foreach ($deployments as $deployment) {
            try {
                $company = $deployment->company;
                if ($company === null) {
                    $this->warn("Skipping deployment {$deployment->id} — no company");

                    continue;
                }

                $count = AgentRuntimeProviderFactory::forDeployment($deployment)
                    ->collectSessionTranscripts(
                        $deployment,
                        $app,
                        $company,
                        $since
                    );

                $totalPersisted += $count;
                $successCount++;
                $this->line(sprintf('[%d] %s: persisted %d new messages', $deployment->id, $deployment->provider ?? '?', $count));
            } catch (Throwable $e) {
                $failureCount++;
                Log::error('CollectAgentSessionTranscriptsCommand: deployment failed', [
                    'deployment_id' => $deployment->id,
                    'provider' => $deployment->provider,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('[%d] FAILED: %s', $deployment->id, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'Done: %d deployments, %d ok, %d failed, %d total messages persisted',
            $deployments->count(),
            $successCount,
            $failureCount,
            $totalPersisted,
        ));

        return $failureCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
