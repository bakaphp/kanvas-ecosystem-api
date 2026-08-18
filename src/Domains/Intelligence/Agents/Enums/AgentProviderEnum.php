<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Identifier for an agent runtime provider.
 *
 * Pure key — no dispatch logic. Resolvers and services that need to act on a provider
 * look the concrete implementation up via {@see \Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory}
 * (which returns an {@see \Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider}).
 *
 * Three families:
 * - In-process (NEURON / LARAVEL / ADK) — run inside the PHP process, nothing to deploy.
 * - Machine runtimes (OPENCLAW / HERMES) — containers we stand up on an AgentMachine.
 * - Hosted runtimes (CLAUDE) — the vendor owns both the agent loop and the sandbox, so there is
 *   no AgentMachine, no ports and no AgentDeployment row; the linkage lives in agent custom fields.
 */
enum AgentProviderEnum: string
{
    case NEURON = 'neuron';
    case LARAVEL = 'laravel';
    case ADK = 'adk';
    case OPENCLAW = 'openclaw';
    case HERMES = 'hermes';
    case CLAUDE = 'claude';

    /**
     * Runtimes that install onto an AgentMachine. Anything fanning out over machines (e.g.
     * AgentMachineMutation::updateContainers) MUST use this list rather than remoteProviders() —
     * a hosted runtime has no machine and every machine op default-throws on it.
     */
    public static function runtimeProviders(): array
    {
        return [
            self::OPENCLAW,
            self::HERMES,
        ];
    }

    /**
     * Vendor-hosted runtimes: loop and sandbox both live on the vendor's infrastructure. We hold
     * a config pointer (agent id / session id), never a container.
     */
    public static function hostedProviders(): array
    {
        return [
            self::CLAUDE,
        ];
    }

    /**
     * Everything executing outside this PHP process. Drives Agent::isContainerRuntime(), which is
     * what routes a chat turn to RunRuntimeChatAction instead of an in-process handler.
     */
    public static function remoteProviders(): array
    {
        return [
            ...self::runtimeProviders(),
            ...self::hostedProviders(),
        ];
    }

    public static function inProcessProviders(): array
    {
        return [
            self::NEURON,
            self::LARAVEL,
            self::ADK,
        ];
    }

    /**
     * Providers whose per-turn token usage is recorded locally in
     * agent_conversation_messages and rolled up by RollupLocalAgentUsageAction.
     * ADK is in-process but metered remotely (Google ADK), so it's excluded here
     * to avoid double-counting.
     *
     * @return list<string>
     */
    public static function localUsageProviderValues(): array
    {
        return [self::NEURON->value, self::LARAVEL->value];
    }

    public function isRuntimeProvider(): bool
    {
        return in_array($this, self::remoteProviders(), true);
    }

    public function isHosted(): bool
    {
        return in_array($this, self::hostedProviders(), true);
    }

    public function isHermes(): bool
    {
        return $this === self::HERMES;
    }

    public function isClaude(): bool
    {
        return $this === self::CLAUDE;
    }

    public function isOpenClaw(): bool
    {
        return $this === self::OPENCLAW;
    }

    public function isNeuron(): bool
    {
        return $this === self::NEURON;
    }

    public function isLaravel(): bool
    {
        return $this === self::LARAVEL;
    }

    public function isAdk(): bool
    {
        return $this === self::ADK;
    }

    /**
     * Resolve the provider from a deployment row, defaulting to OPENCLAW if the column is empty
     * (legacy rows from before the provider field existed) OR carries a value that isn't a valid
     * runtime provider (e.g. an LLM name like "anthropic" that leaked into the column). Never throws —
     * a bad provider on one deployment must not crash every plan-change / kanban-sync path that reads
     * it. Wrapped here so resolvers don't repeat the strtolower/tryFrom/fallback dance.
     */
    public static function forDeployment(AgentDeployment $deployment): self
    {
        $raw = $deployment->provider;
        if ($raw === null || $raw === '') {
            return self::OPENCLAW;
        }

        return self::tryFrom(strtolower($raw)) ?? self::OPENCLAW;
    }
}
