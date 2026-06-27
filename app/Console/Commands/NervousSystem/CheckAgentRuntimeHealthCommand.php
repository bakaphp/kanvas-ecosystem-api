<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\AgentRuntime\Services\AgentAwakeStateWriterService;
use Kanvas\Intelligence\Agents\Enums\AgentAwakeStateEnum;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Throwable;

class CheckAgentRuntimeHealthCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:agent-runtime-check-health {--app= : Restrict to a single app id}';

    protected $description = 'Reconcile awake_state across every agent — probes runtime deployments + in-process providers';

    public function handle(): int
    {
        $appId = $this->option('app') !== null ? (int) $this->option('app') : null;

        if ($appId !== null) {
            /** @var Apps $app */
            $app = Apps::getById($appId);
            $this->overwriteAppService($app);
        }

        $counts = [
            HealthCheckResultEnum::OK->value => 0,
            HealthCheckResultEnum::FAILED->value => 0,
            HealthCheckResultEnum::UNSUPPORTED->value => 0,
        ];
        $errored = 0;
        $inProcessReconciled = 0;

        $this->probeRuntimeDeployments($appId, $counts, $errored);
        $this->reconcileInProcessAgents($appId, $inProcessReconciled);

        $this->info(sprintf(
            'Runtime probed: ok=%d failed=%d unsupported=%d errored=%d | In-process state changes: %d',
            $counts[HealthCheckResultEnum::OK->value],
            $counts[HealthCheckResultEnum::FAILED->value],
            $counts[HealthCheckResultEnum::UNSUPPORTED->value],
            $errored,
            $inProcessReconciled,
        ));

        return self::SUCCESS;
    }

    private function probeRuntimeDeployments(?int $appId, array &$counts, int &$errored): void
    {
        $query = AgentDeployment::query()
            ->where('status', DeploymentStatusEnum::RUNNING->value)
            ->notDeleted();

        if ($appId !== null) {
            $query->where('apps_id', $appId);
        }

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
                } catch (ModelNotFoundException $e) {
                    // Orphaned deployment: its agent/app/company was deleted but the row is still
                    // running, so the probe can never succeed. Terminate it so it drops out of the
                    // running query instead of re-reporting to Sentry on every cron run.
                    $deployment->status = DeploymentStatusEnum::TERMINATED->value;
                    $deployment->terminated_at = now();
                    $deployment->error_message = $e->getMessage();
                    $deployment->saveOrFail();

                    $this->warn(sprintf(
                        '  deployment=%d orphaned (agent missing) → terminated',
                        $deployment->getId(),
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
    }

    // In-process providers (Neuron/Laravel/ADK) don't have containers to probe — their "health"
    // is whether the Agent row is active. We reconcile by walking those agents and flipping
    // awake_state via the same transitioner the runtime probe uses, so dashboards see uniform
    // ledger events regardless of provider type.
    private function reconcileInProcessAgents(?int $appId, int &$reconciled): void
    {
        $providerValues = array_map(
            static fn (AgentProviderEnum $p): string => $p->value,
            AgentProviderEnum::inProcessProviders(),
        );

        $query = Agent::query()
            ->notDeleted()
            ->whereHas(
                'type',
                static fn ($q) => $q->whereIn('provider', $providerValues),
            );

        if ($appId !== null) {
            $query->where('apps_id', $appId);
        }

        $writer = new AgentAwakeStateWriterService();

        $query->chunkById(50, function ($agents) use ($writer, &$reconciled): void {
            foreach ($agents as $agent) {
                $expected = $agent->is_active
                    ? AgentAwakeStateEnum::AWAKE
                    : AgentAwakeStateEnum::OFFLINE;

                $eventStatus = $expected === AgentAwakeStateEnum::AWAKE
                    ? EventStatusEnum::SUCCESS
                    : EventStatusEnum::ERROR;

                /** @var Apps $app */
                $app = Apps::getById($agent->apps_id);
                $company = Companies::getById($agent->companies_id);

                $changed = $writer->write(
                    $agent,
                    $app,
                    $company,
                    $expected,
                    $eventStatus,
                );

                if ($changed) {
                    $reconciled++;
                    $this->line(sprintf(
                        '  agent=%-5d provider=%-8s %-30s → %s',
                        $agent->getId(),
                        $agent->type?->provider ?? '?',
                        substr($agent->name, 0, 30),
                        $expected->value,
                    ));
                }
            }
        });
    }
}
