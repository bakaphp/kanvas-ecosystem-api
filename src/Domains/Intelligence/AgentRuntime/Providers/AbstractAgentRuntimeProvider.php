<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use LogicException;
use Override;

/**
 * Default-throws implementation of every {@see AgentRuntimeProvider} method.
 *
 * Concrete providers extend this and override only the operations they actually support.
 * Anything they leave unoverridden surfaces as a clear `LogicException` instead of a
 * silent crash deep inside a hardcoded connector call.
 *
 * Add a new lifecycle method to the interface → add the default-throws stub here →
 * existing providers stay compilable, only the ones that support it have to be updated.
 */
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
    public function setSlackTokens(Agent $agent, string $botToken, string $appToken): void
    {
        throw $this->unsupported('Slack tokens');
    }

    #[Override]
    public function setTelegramToken(Agent $agent, string $botToken): void
    {
        throw $this->unsupported('Telegram tokens');
    }

    protected function unsupported(string $operation): LogicException
    {
        return new LogicException(
            sprintf('Provider [%s] does not support %s.', $this->name()->value, $operation),
        );
    }
}
