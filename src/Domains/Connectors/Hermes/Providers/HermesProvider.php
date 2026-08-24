<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Hermes\Actions\BackupAgentWorkspaceAction;
use Kanvas\Connectors\Hermes\Actions\ChatWithAgentAction;
use Kanvas\Connectors\Hermes\Actions\CheckApiHealthAction;
use Kanvas\Connectors\Hermes\Actions\CollectDeploymentUsageAction;
use Kanvas\Connectors\Hermes\Actions\CollectSessionTranscriptsAction;
use Kanvas\Connectors\Hermes\Actions\DispatchAgentDeploymentAction;
use Kanvas\Connectors\Hermes\Actions\ExecDeploymentCommandAction;
use Kanvas\Connectors\Hermes\Actions\FetchDailyLearningContextAction;
use Kanvas\Connectors\Hermes\Actions\GetAgentContainerLogsAction;
use Kanvas\Connectors\Hermes\Actions\GetAgentContainerStatusAction;
use Kanvas\Connectors\Hermes\Actions\GetDeploymentConfigAction;
use Kanvas\Connectors\Hermes\Actions\PushDailyLearningContextAction;
use Kanvas\Connectors\Hermes\Actions\UpdateDeploymentConfigAction;
use Kanvas\Connectors\Hermes\Jobs\BackupAgentWorkspaceJob;
use Kanvas\Connectors\Hermes\Jobs\MigrateAgentWorkspaceJob;
use Kanvas\Connectors\Hermes\Jobs\MigrateFromOpenClawJob;
use Kanvas\Connectors\Hermes\Jobs\RestartAgentContainerJob;
use Kanvas\Connectors\Hermes\Jobs\SyncDeploymentCredentialsJob;
use Kanvas\Connectors\Hermes\Jobs\TerminateAgentJob;
use Kanvas\Connectors\Hermes\Jobs\UpdateHermesOnMachineJob;
use Kanvas\Connectors\Hermes\Jobs\UpdateWorkspaceFilesJob;
use Kanvas\Connectors\Hermes\Kanban\Actions\CommentKanbanTaskAction;
use Kanvas\Connectors\Hermes\Kanban\Actions\CreateKanbanTaskAction;
use Kanvas\Connectors\Hermes\Kanban\Actions\EnsureKanbanWritableAction;
use Kanvas\Connectors\Hermes\Kanban\Actions\FetchKanbanBoardAction;
use Kanvas\Connectors\Hermes\Kanban\Actions\FetchKanbanTaskAction;
use Kanvas\Connectors\Hermes\Kanban\Actions\TransitionKanbanTaskAction;
use Kanvas\Connectors\Hermes\Kanban\Support\HermesProfileResolver;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTaskInput;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanTransition;
use Kanvas\Intelligence\AgentRuntime\Providers\AbstractAgentRuntimeProvider;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Override;

/**
 * Hermes runtime provider — full lifecycle implementation backed by Hermes's first-class CLI
 * (https://hermes-agent.nousresearch.com/docs/reference/cli-commands).
 *
 * Most operations mirror OpenClaw's Docker-isolation pattern (SSH into the machine, `docker
 * exec` into the container) parameterized through `ProviderConfig` — so logs/status/restart
 * etc. live on the `Base*` actions and this class only supplies the SshClient factory at
 * dispatch time. The few Hermes-specific bits (YAML config codec, `hermes backup --quick -o`,
 * `hermes insights --json`) are encapsulated in the matching `Connectors/Hermes/Actions/*`
 * subclass.
 *
 * Cross-runtime adoption ({@see dispatchAdoptForeignDeployment}) consumes an OpenClaw
 * deployment and stands it back up under Hermes — currently the only direction that exists.
 */
class HermesProvider extends AbstractAgentRuntimeProvider
{
    #[Override]
    public function name(): AgentProviderEnum
    {
        return AgentProviderEnum::HERMES;
    }

    #[Override]
    public function dispatchDeployment(
        Agent $agent,
        AgentMachine $machine,
        AppInterface $app,
        CompanyInterface $company,
    ): AgentDeployment {
        return new DispatchAgentDeploymentAction($agent, $machine, $app, $company)->execute();
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
    public function fetchContainerStatus(AgentDeployment $deployment): AgentDeployment
    {
        return new GetAgentContainerStatusAction($deployment)->execute();
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
    public function collectSessionTranscripts(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        ?Carbon $since = null,
    ): int {
        return new CollectSessionTranscriptsAction(
            $deployment,
            $app,
            $company,
            $since
        )->execute();
    }

    #[Override]
    public function pushDailyLearningContext(
        AgentDeployment $deployment,
        DailyLearningSummary $summary,
        Carbon $cycleDate,
    ): bool {
        return new PushDailyLearningContextAction(
            $deployment,
            $summary,
            $cycleDate
        )->execute();
    }

    #[Override]
    public function fetchDailyLearningContext(AgentDeployment $deployment): string
    {
        return new FetchDailyLearningContextAction($deployment)->execute();
    }

    #[Override]
    public function execCommand(AgentDeployment $deployment, string $command, string $sessionId): bool
    {
        return new ExecDeploymentCommandAction(
            $deployment,
            $command,
            $sessionId
        )->execute();
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
        return new BackupAgentWorkspaceAction($deployment, $backup, includeWorkspace: true)->execute();
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
        UpdateHermesOnMachineJob::dispatch($machine);
    }

    #[Override]
    public function dispatchWorkspaceUpdate(AgentDeployment $deployment): void
    {
        UpdateWorkspaceFilesJob::dispatch($deployment);
    }

    /**
     * Cross-runtime adoption — converts an OpenClaw (source) deployment into a Hermes
     * deployment running on `$destinationMachine`. Today Hermes is the only adoption
     * target; if a non-OpenClaw source ever appears the migration job rejects upfront.
     */
    #[Override]
    public function dispatchAdoptForeignDeployment(
        AgentDeployment $sourceDeployment,
        AgentMachine $destinationMachine,
        AppInterface $app,
        CompanyInterface $company,
        ?string $sourcePath,
        ?string $destinationPath,
    ): void {
        MigrateFromOpenClawJob::dispatch(
            $sourceDeployment,
            $destinationMachine,
            $app,
            $company,
            $sourcePath,
            $destinationPath,
        );
    }

    /**
     * Provider-agnostic parsed-log fetch — uses the same SshClient base method as OpenClaw.
     */
    #[Override]
    public function fetchDeploymentLogs(AgentDeployment $deployment, int $limit): array
    {
        if ($deployment->machine === null || $deployment->agent === null) {
            return [];
        }

        $ssh = SshClient::fromMachine($deployment->machine);

        try {
            return $ssh->getDeploymentLogs($deployment->container_name, $deployment->agent->slug, $limit);
        } finally {
            $ssh->disconnect();
        }
    }

    #[Override]
    public function checkHealth(AgentDeployment $deployment): HealthCheckResultEnum
    {
        return new CheckApiHealthAction($deployment)->execute();
    }

    /**
     * Hermes /v1/chat/completions is stateless, so $sessionKey is unused — cross-turn
     * continuity comes from the agent's own persistent memory.
     */
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
            $images
        )->execute();
    }

    #[Override]
    public function fetchKanbanBoard(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        ?string $assignee = null,
        ?string $tenant = null,
        ?string $board = null,
        array $knownTaskIds = [],
    ): array {
        return new FetchKanbanBoardAction(
            $deployment,
            $this->resolveAssignee($assignee, $deployment),
            $tenant,
            $board,
            $knownTaskIds,
        )->execute();
    }

    #[Override]
    public function fetchKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        string $externalTaskId,
        ?string $board = null,
    ): ?KanbanTask {
        return new FetchKanbanTaskAction($deployment, $externalTaskId, $board)->execute();
    }

    #[Override]
    public function createKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        KanbanTaskInput $input,
        ?string $board = null,
    ): KanbanTask {
        // Make the board dir writable by the worker before the first push (no-op if already 777).
        new EnsureKanbanWritableAction($deployment)->execute();

        $assignee = $this->resolveAssignee($input->assignee, $deployment);

        if ($assignee !== $input->assignee) {
            $input = new KanbanTaskInput(
                title: $input->title,
                assignee: $assignee,
                body: $input->body,
                idempotencyKey: $input->idempotencyKey,
                parentIds: $input->parentIds,
                priority: $input->priority,
                tenant: $input->tenant,
                workspace: $input->workspace,
            );
        }

        return new CreateKanbanTaskAction($deployment, $input, $board)->execute();
    }

    // An unknown/empty assignee is silently dropped by the Hermes dispatcher, so default it to
    // the deployment's own agent profile — the agnostic caller doesn't know the Hermes profile name.
    private function resolveAssignee(?string $assignee, AgentDeployment $deployment): ?string
    {
        if ($assignee !== null && $assignee !== '') {
            return $assignee;
        }

        return $deployment->agent !== null
            ? HermesProfileResolver::forAgent($deployment->agent)
            : $assignee;
    }

    #[Override]
    public function transitionKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        string $externalTaskId,
        KanbanTransition $transition,
        ?string $reason = null,
        ?string $assignee = null,
        ?string $result = null,
        ?string $board = null,
    ): KanbanTask {
        return new TransitionKanbanTaskAction(
            $deployment,
            $externalTaskId,
            $transition,
            $reason,
            $assignee,
            $result,
            $board,
        )->execute();
    }

    #[Override]
    public function commentKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        string $externalTaskId,
        string $text,
        string $author,
        ?string $board = null,
    ): void {
        new CommentKanbanTaskAction(
            $deployment,
            $externalTaskId,
            $text,
            $author,
            $board,
        )->execute();
    }
}
