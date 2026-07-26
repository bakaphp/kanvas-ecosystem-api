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
 * NEURON / LARAVEL / ADK are in-process providers that don't deploy as containers;
 * OPENCLAW / HERMES are the runtime providers that the factory has concrete implementations
 * for today.
 */
enum AgentProviderEnum: string
{
    case NEURON = 'neuron';
    case LARAVEL = 'laravel';
    case ADK = 'adk';
    case OPENCLAW = 'openclaw';
    case HERMES = 'hermes';

    public static function runtimeProviders(): array
    {
        return [
            self::OPENCLAW,
            self::HERMES,
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
        return in_array($this, self::runtimeProviders(), true);
    }

    public function isHermes(): bool
    {
        return $this === self::HERMES;
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
