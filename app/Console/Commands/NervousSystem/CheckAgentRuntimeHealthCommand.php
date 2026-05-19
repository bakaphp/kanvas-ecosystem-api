<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * Unified runtime liveness probe. Walks every running deployment, routes through
 * `AgentRuntimeProviderFactory::forDeployment()`, and lets each runtime answer "are you alive"
 * its own way (Hermes does HTTP /health; future runtimes plug into the same call). Providers
 * that don't expose a probe yet return `UNSUPPORTED` from the abstract default — those are
 * counted and skipped silently.
 *
 * Lives in NervousSystem (not Intelligence/AgentRuntime) because it emits ledger events and
 * drives `agents.awake_state` — both Nervous System concerns. Sits next to RecordAgentDailyCycles
 * which consumes those same signals.
 *
 * Scheduled every 10 minutes from app/Console/Kernel.php — see `BaseCheckHealthAction` for the
 * 2-strike state machine that follows the probe.
 *
 *   php artisan kanvas:agent-runtime-check-health
 *   php artisan kanvas:agent-runtime-check-health --app=42
 */
class CheckAgentRuntimeHealthCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:agent-runtime-check-health {--app= : Restrict to a single app id}';

    protected $description = 'Probe every running agent-runtime deployment and update awake_state on outage/recovery';

    public function handle(): int
    {
        $query = AgentDeployment::query()
            ->where('status', 'running')
            ->where('is_deleted', 0);

        if ($this->option('app') !== null) {
            $appId = (int) $this->option('app');
            /** @var Apps $app */
            $app = Apps::getById($appId);
            $this->overwriteAppService($app);
            $query->where('apps_id', $appId);
        }

        $counts = [
            HealthCheckResultEnum::OK->value => 0,
            HealthCheckResultEnum::FAILED->value => 0,
            HealthCheckResultEnum::UNSUPPORTED->value => 0,
        ];
        $errored = 0;

        $query->chunkById(50, function ($deployments) use (&$counts, &$errored): void {
            foreach ($deployments as $deployment) {
                try {
                    $provider = AgentRuntimeProviderFactory::forDeployment($deployment);
                    $result = $provider->checkHealth($deployment);
                    $counts[$result->value]++;
                    $this->line(sprintf(
                        '  deployment=%-5d provider=%-8s %-30s → %s',
                        $deployment->getId(),
                        $deployment->provider ?? '?',
                        substr($deployment->container_name, 0, 30),
                        $result->value,
                    ));
                } catch (Throwable $e) {
                    $errored++;
                    report($e);
                    $this->error(sprintf(
                        '  deployment=%d failed: %s',
                        $deployment->getId(),
                        $e->getMessage(),
                    ));
                }
            }
        });

        $this->info(sprintf(
            'Probed: ok=%d failed=%d unsupported=%d errored=%d',
            $counts[HealthCheckResultEnum::OK->value],
            $counts[HealthCheckResultEnum::FAILED->value],
            $counts[HealthCheckResultEnum::UNSUPPORTED->value],
            $errored,
        ));

        return self::SUCCESS;
    }
}
