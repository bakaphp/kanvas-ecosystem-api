<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTaskInput;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanTransition;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use LogicException;
use Override;

// Every interface method default-throws here. Concrete providers override only the ops they
// support; calls into a missing op surface a clear LogicException at the resolver instead of
// crashing deep inside a connector. Add a new interface method → add a stub here → partial
// connectors stay compilable.
abstract class AbstractAgentRuntimeProvider implements AgentRuntimeProvider
{
    #[Override]
    public function dispatchDeployment(
        Agent $agent,
        AgentMachine $machine,
        AppInterface $app,
        CompanyInterface $company,
    ): AgentDeployment {
        throw $this->unsupported('agent deployment');
    }

    #[Override]
    public function dispatchTermination(AgentDeployment $deployment): void
    {
        throw $this->unsupported('termination');
    }

    #[Override]
    public function dispatchRestart(AgentDeployment $deployment): void
    {
        throw $this->unsupported('container restart');
    }

    #[Override]
    public function dispatchCredentialSync(AgentDeployment $deployment): void
    {
        throw $this->unsupported('credential sync');
    }

    #[Override]
    public function fetchContainerLogs(AgentDeployment $deployment, int $lines): string
    {
        throw $this->unsupported('container log fetching');
    }

    #[Override]
    public function fetchDeploymentLogs(AgentDeployment $deployment, int $limit): array
    {
        throw $this->unsupported('parsed deployment log fetching');
    }

    #[Override]
    public function fetchTelemetrySnapshot(AgentDeployment $deployment): ?array
    {
        // No telemetry collector wired by default — concrete providers override this
        // and read from their own cache key. Returning null is the safe default for the
        // resolver: "no data yet" instead of a thrown LogicException.
        return null;
    }

    #[Override]
    public function fetchContainerStatus(AgentDeployment $deployment): AgentDeployment
    {
        throw $this->unsupported('container status probing');
    }

    #[Override]
    public function collectUsage(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
    ): AgentUsageSnapshot {
        throw $this->unsupported('usage collection');
    }

    #[Override]
    public function execCommand(AgentDeployment $deployment, string $command, string $sessionId): bool
    {
        throw $this->unsupported('exec command');
    }

    #[Override]
    public function fetchConfig(AgentDeployment $deployment): string
    {
        throw $this->unsupported('config read');
    }

    #[Override]
    public function updateConfig(AgentDeployment $deployment, string $config): bool
    {
        throw $this->unsupported('config update');
    }

    #[Override]
    public function dispatchBackup(AgentDeployment $deployment, AgentBackup $backup, bool $includeWorkspace): void
    {
        throw $this->unsupported('workspace backup');
    }

    #[Override]
    public function createWorkspaceBackupNow(AgentDeployment $deployment, AgentBackup $backup): AgentBackup
    {
        throw $this->unsupported('synchronous workspace backup');
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
        throw $this->unsupported('workspace migration');
    }

    #[Override]
    public function dispatchAdoptForeignDeployment(
        AgentDeployment $sourceDeployment,
        AgentMachine $destinationMachine,
        AppInterface $app,
        CompanyInterface $company,
        ?string $sourcePath,
        ?string $destinationPath,
    ): void {
        throw $this->unsupported('cross-runtime adoption');
    }

    #[Override]
    public function dispatchUpdateMachineContainers(AgentMachine $machine): void
    {
        throw $this->unsupported('machine container updates');
    }

    #[Override]
    public function dispatchWorkspaceUpdate(AgentDeployment $deployment): void
    {
        throw $this->unsupported('workspace file update');
    }

    #[Override]
    public function checkHealth(AgentDeployment $deployment): HealthCheckResultEnum
    {
        return HealthCheckResultEnum::UNSUPPORTED;
    }

    #[Override]
    public function chat(
        Agent $agent,
        string $message,
        ?string $sessionKey = null,
        array $images = [],
        array $additionalTools = [],
    ): string {
        throw $this->unsupported('chat');
    }

    #[Override]
    public function collectSessionTranscripts(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        ?Carbon $since = null,
    ): int {
        throw $this->unsupported('session transcript collection');
    }

    // Returning false (not throwing) is intentional: pushing the daily learning
    // back into the agent's own memory is optional — the Kanvas-side persistence
    // (AgentDailyCycle row, digest email) still completes regardless of whether
    // the runtime supports a memory-bank write path. Hermes overrides this with
    // a MEMORY.md append; OpenClaw will once we wire its memory CLI in v1.1.
    #[Override]
    public function pushDailyLearningContext(
        AgentDeployment $deployment,
        DailyLearningSummary $summary,
        Carbon $cycleDate,
    ): bool {
        return false;
    }

    // Empty string is the contract default — runtimes that don't expose their
    // memory bank for inspection (OpenClaw v1) still satisfy the contract;
    // the LLM-dedup prompt just won't have prior facts to skip.
    #[Override]
    public function fetchDailyLearningContext(AgentDeployment $deployment): string
    {
        return '';
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
        throw $this->unsupported('kanban board read');
    }

    #[Override]
    public function fetchKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        string $externalTaskId,
        ?string $board = null,
    ): ?KanbanTask {
        throw $this->unsupported('kanban task read');
    }

    #[Override]
    public function createKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        KanbanTaskInput $input,
        ?string $board = null,
    ): KanbanTask {
        throw $this->unsupported('kanban task creation');
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
        throw $this->unsupported('kanban task transition');
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
        throw $this->unsupported('kanban task comment');
    }

    protected function unsupported(string $operation): LogicException
    {
        return new LogicException(
            sprintf('Provider [%s] does not support %s.', $this->name()->value, $operation),
        );
    }
}
