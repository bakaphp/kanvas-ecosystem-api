<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Contracts;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;

// One implementation per runtime backend (OpenClaw, Hermes, future Nano). Resolvers and
// services look the concrete up via AgentRuntimeProviderFactory. Partial implementations
// extend AbstractAgentRuntimeProvider and inherit default-throws for anything they don't
// support yet.
interface AgentRuntimeProvider
{
    public function name(): AgentProviderEnum;

    public function dispatchDeployment(
        Agent $agent,
        AgentMachine $machine,
        AppInterface $app,
        CompanyInterface $company,
    ): AgentDeployment;

    public function dispatchTermination(AgentDeployment $deployment): void;

    public function dispatchRestart(AgentDeployment $deployment): void;

    public function fetchContainerLogs(AgentDeployment $deployment, int $lines): string;

    // Parsed log entries — distinct from fetchContainerLogs, which returns the raw stdout dump.
    /** @return array<int, array{ts:string,level:string,msg:string,meta:string|null}> */
    public function fetchDeploymentLogs(AgentDeployment $deployment, int $limit): array;

    // Latest snapshot the background collector wrote, or null if none yet.
    // Cache key shape is provider-internal.
    /** @return array<string, mixed>|null */
    public function fetchTelemetrySnapshot(AgentDeployment $deployment): ?array;

    public function fetchContainerStatus(AgentDeployment $deployment): AgentDeployment;

    public function collectUsage(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
    ): AgentUsageSnapshot;

    public function execCommand(AgentDeployment $deployment, string $command, string $sessionId): bool;

    public function fetchConfig(AgentDeployment $deployment): string;

    public function updateConfig(AgentDeployment $deployment, string $config): bool;

    public function dispatchBackup(AgentDeployment $deployment, AgentBackup $backup, bool $includeWorkspace): void;

    public function dispatchMigrateWorkspace(
        AgentDeployment $sourceDeployment,
        AgentMachine $destinationMachine,
        AppInterface $app,
        CompanyInterface $company,
        ?string $sourcePath,
        ?string $destinationPath,
    ): void;

    // Cross-runtime adoption: THIS provider takes over a deployment currently running under
    // a different runtime. Today only Hermes implements being a target (adopts OpenClaw).
    public function dispatchAdoptForeignDeployment(
        AgentDeployment $sourceDeployment,
        AgentMachine $destinationMachine,
        AppInterface $app,
        CompanyInterface $company,
        ?string $sourcePath,
        ?string $destinationPath,
    ): void;

    public function dispatchUpdateMachineContainers(AgentMachine $machine): void;

    public function dispatchWorkspaceUpdate(AgentDeployment $deployment): void;

    // Runtime liveness probe — drives the unified health-check cron + dashboard "is offline" pill.
    // Returns UNSUPPORTED for runtimes that don't expose a probe yet (default in the abstract);
    // OK/FAILED feed the 2-strike state machine in `BaseCheckHealthAction`.
    public function checkHealth(AgentDeployment $deployment): HealthCheckResultEnum;
}
