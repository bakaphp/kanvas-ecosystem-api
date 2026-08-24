<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\OpenClaw\Actions\BackupAgentWorkspaceAction;
use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentAction;
use Kanvas\Connectors\OpenClaw\Actions\CheckCliHealthAction;
use Kanvas\Connectors\OpenClaw\Actions\CollectDeploymentUsageAction;
use Kanvas\Connectors\OpenClaw\Actions\CollectSessionTranscriptsAction;
use Kanvas\Connectors\OpenClaw\Actions\DispatchAgentDeploymentAction;
use Kanvas\Connectors\OpenClaw\Actions\ExecDeploymentCommandAction;
use Kanvas\Connectors\OpenClaw\Actions\FetchDailyLearningContextAction;
use Kanvas\Connectors\OpenClaw\Actions\GetAgentContainerLogsAction;
use Kanvas\Connectors\OpenClaw\Actions\GetAgentContainerStatusAction;
use Kanvas\Connectors\OpenClaw\Actions\GetDeploymentConfigAction;
use Kanvas\Connectors\OpenClaw\Actions\PushDailyLearningContextAction;
use Kanvas\Connectors\OpenClaw\Actions\UpdateDeploymentConfigAction;
use Kanvas\Connectors\OpenClaw\Jobs\BackupAgentWorkspaceJob;
use Kanvas\Connectors\OpenClaw\Jobs\MigrateAgentWorkspaceJob;
use Kanvas\Connectors\OpenClaw\Jobs\RestartAgentContainerJob;
use Kanvas\Connectors\OpenClaw\Jobs\SyncDeploymentCredentialsJob;
use Kanvas\Connectors\OpenClaw\Jobs\TerminateAgentJob;
use Kanvas\Connectors\OpenClaw\Jobs\UpdateOpenClawOnMachineJob;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AbstractAgentRuntimeProvider;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Override;

/**
 * OpenClaw runtime provider — full implementation of every AgentRuntime lifecycle op.
 *
 * Each method maps to the matching `Connectors/OpenClaw/Actions` or `Connectors/OpenClaw/Jobs`
 * class. The resolver picks this provider when `agent_deployments.provider = 'openclaw'`.
 */
class OpenClawProvider extends AbstractAgentRuntimeProvider
{
    #[Override]
    public function name(): AgentProviderEnum
    {
        return AgentProviderEnum::OPENCLAW;
    }

    #[Override]
    public function dispatchDeployment(
        Agent $agent,
        AgentMachine $machine,
        AppInterface $app,
        CompanyInterface $company,
    ): AgentDeployment {
        return new DispatchAgentDeploymentAction(
            $agent,
            $machine,
            $app,
            $company
        )->execute();
    }

    #[Override]
    public function dispatchTermination(AgentDeployment $deployment): void
    {
        TerminateAgentJob::dispatch($deployment);
    }

    #[Override]
    public function dispatchRestart(AgentDeployment $deployment): void
    {
        RestartAgentContainerJob::dispatch($deployment);
    }

    #[Override]
    public function dispatchCredentialSync(AgentDeployment $deployment): void
    {
        SyncDeploymentCredentialsJob::dispatch($deployment);
    }

    #[Override]
    public function fetchContainerLogs(AgentDeployment $deployment, int $lines): string
    {
        return new GetAgentContainerLogsAction($deployment, $lines)->execute();
    }

    #[Override]
    public function fetchTelemetrySnapshot(AgentDeployment $deployment): ?array
    {
        // Key written by Connectors\OpenClaw\Services\AgentTelemetryService; the resolver
        // stays provider-agnostic by routing through here instead of reading the key directly.
        /** @var array<string, mixed>|null */
        return Cache::get('openclaw:telemetry:' . $deployment->id);
    }

    #[Override]
    public function fetchDeploymentLogs(AgentDeployment $deployment, int $limit): array
    {
        if ($deployment->machine === null || $deployment->agent === null) {
            return [];
        }

        $ssh = SshClient::fromMachine($deployment->machine);

        try {
            return $ssh->getDeploymentLogs(
                $deployment->container_name,
                $deployment->agent->slug,
                $limit
            );
        } finally {
            $ssh->disconnect();
        }
    }

    #[Override]
    public function fetchContainerStatus(AgentDeployment $deployment): AgentDeployment
    {
        return new GetAgentContainerStatusAction($deployment)->execute();
    }

    #[Override]
    public function checkHealth(AgentDeployment $deployment): HealthCheckResultEnum
    {
        return new CheckCliHealthAction($deployment)->execute();
    }

    #[Override]
    public function chat(
        Agent $agent,
        string $message,
        ?string $sessionKey = null,
        array $images = [],
        array $additionalTools = [],
    ): string {
        return new ChatWithAgentAction(
            $agent,
            $message,
            $sessionKey,
            $images,
        )->execute();
    }

    #[Override]
    public function collectUsage(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
    ): AgentUsageSnapshot {
        return new CollectDeploymentUsageAction($deployment, $app, $company)->execute();
    }

    #[Override]
    public function execCommand(AgentDeployment $deployment, string $command, string $sessionId): bool
    {
        return new ExecDeploymentCommandAction($deployment, $command, $sessionId)->execute();
    }

    #[Override]
    public function fetchConfig(AgentDeployment $deployment): string
    {
        return new GetDeploymentConfigAction($deployment)->execute();
    }

    #[Override]
    public function updateConfig(AgentDeployment $deployment, string $config): bool
    {
        return new UpdateDeploymentConfigAction($deployment, $config)->execute();
    }

    #[Override]
    public function dispatchBackup(AgentDeployment $deployment, AgentBackup $backup, bool $includeWorkspace): void
    {
        BackupAgentWorkspaceJob::dispatch($deployment, $backup, $includeWorkspace);
    }

    #[Override]
    public function createWorkspaceBackupNow(AgentDeployment $deployment, AgentBackup $backup): AgentBackup
    {
        return (new BackupAgentWorkspaceAction($deployment, $backup, includeWorkspace: true))->execute();
    }

    #[Override]
    public function dispatchMigrateWorkspace(
        AgentDeployment $sourceDeployment,
        AgentMachine $destinationMachine,
        AppInterface $app,
        CompanyInterface $company,
        ?string $sourcePath,
        ?string $destinationPath,
    ): void {
        MigrateAgentWorkspaceJob::dispatch(
            $sourceDeployment,
            $destinationMachine,
            $app,
            $company,
            $sourcePath,
            $destinationPath,
        );
    }

    #[Override]
    public function dispatchUpdateMachineContainers(AgentMachine $machine): void
    {
        UpdateOpenClawOnMachineJob::dispatch($machine);
    }

    #[Override]
    public function collectSessionTranscripts(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        ?Carbon $since = null,
    ): int {
        return new CollectSessionTranscriptsAction(
            deployment: $deployment,
            app: $app,
            company: $company,
            since: $since,
        )->execute();
    }

    #[Override]
    public function pushDailyLearningContext(
        AgentDeployment $deployment,
        DailyLearningSummary $summary,
        Carbon $cycleDate,
    ): bool {
        return new PushDailyLearningContextAction(
            deployment: $deployment,
            summary: $summary,
            cycleDate: $cycleDate,
        )->execute();
    }

    #[Override]
    public function fetchDailyLearningContext(AgentDeployment $deployment): string
    {
        return new FetchDailyLearningContextAction($deployment)->execute();
    }
}
