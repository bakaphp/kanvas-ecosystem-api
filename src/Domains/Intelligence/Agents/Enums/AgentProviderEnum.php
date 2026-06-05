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

    public function isRuntimeProvider(): bool
    {
        return in_array($this, self::runtimeProviders(), true);
    }

    /**
     * Resolve the provider from a deployment row, defaulting to OPENCLAW if the column is empty
     * (legacy rows from before the provider field existed). Wrapped here so resolvers don't
     * repeat the strtolower/from/fallback dance.
     */
    public static function forDeployment(AgentDeployment $deployment): self
    {
        $raw = $deployment->provider;
        if ($raw === null || $raw === '') {
            return self::OPENCLAW;
        }

        return self::from(strtolower((string) $raw));
    }
}
