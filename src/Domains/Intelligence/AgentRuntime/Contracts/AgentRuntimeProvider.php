<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Contracts;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;

/**
 * Per-runtime strategy for the AgentRuntime primary domain.
 *
 * One implementation per runtime backend (OpenClaw, Hermes, future Nano). Resolvers and
 * services look the concrete up via {@see \Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory}
 * — a stateless static match — so they never import provider-specific code directly.
 *
 * Implementations that don't yet support a given operation should extend
 * {@see \Kanvas\Intelligence\AgentRuntime\Providers\AbstractAgentRuntimeProvider}
 * and let its default-throwing methods stand in until the connector catches up.
 */
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

    /**
     * Fetch structured (parsed) log entries from the deployment — distinct from
     * `fetchContainerLogs`, which returns the raw stdout/stderr dump. Each returned
     * entry has at minimum `ts`, `level`, `msg`, and optionally `meta` (JSON-encoded).
     *
     * @return array<int, array{ts:string,level:string,msg:string,meta:string|null}>
     */
    public function fetchDeploymentLogs(AgentDeployment $deployment, int $limit): array;

    /**
     * Read the latest telemetry snapshot the background collector wrote for this deployment.
     * Returns null when no snapshot exists yet (deployment too new, collector not running).
     *
     * Cache key shape and write cadence are provider-internal — the resolver only needs the
     * decoded payload.
     *
     * @return array<string, mixed>|null
     */
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

    /**
     * Cross-runtime migration: the THIS provider adopts the `$sourceDeployment` (which lives
     * on a different runtime today) onto `$destinationMachine`. Implementations decide whether
     * they support being a migration target — Hermes does today; OpenClaw does not.
     */
    public function dispatchAdoptForeignDeployment(
        AgentDeployment $sourceDeployment,
        AgentMachine $destinationMachine,
        AppInterface $app,
        CompanyInterface $company,
        ?string $sourcePath,
        ?string $destinationPath,
    ): void;

    public function dispatchUpdateMachineContainers(AgentMachine $machine): void;

    public function setSlackTokens(Agent $agent, string $botToken, string $appToken): void;

    public function setTelegramToken(Agent $agent, string $botToken): void;
}
