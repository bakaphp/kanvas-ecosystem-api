<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Providers;

use Kanvas\Connectors\Hermes\Providers\HermesProvider;
use Kanvas\Connectors\OpenClaw\Providers\OpenClawProvider;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use ValueError;

final class AgentRuntimeProviderFactory
{
    public static function forProvider(AgentProviderEnum $provider): AgentRuntimeProvider
    {
        return match ($provider) {
            AgentProviderEnum::OPENCLAW => new OpenClawProvider(),
            AgentProviderEnum::HERMES => new HermesProvider(),
            default => throw new ValueError("Provider [{$provider->value}] has no runtime implementation."),
        };
    }

    public static function forDeployment(AgentDeployment $deployment): AgentRuntimeProvider
    {
        return self::forProvider(AgentProviderEnum::forDeployment($deployment));
    }

    /**
     * Pick the provider for an agent that hasn't been deployed yet. Reads from
     * `agent_types.provider` (the agent type is the source of truth — an agent inherits its
     * runtime from its type). Falls back to OPENCLAW when the type predates the provider
     * column or carries an unrecognized value, so legacy agents stay launchable.
     */
    public static function forAgent(Agent $agent): AgentRuntimeProvider
    {
        $raw = $agent->agentType?->provider;

        if (! is_string($raw) || $raw === '') {
            return self::forProvider(AgentProviderEnum::OPENCLAW);
        }

        try {
            return self::forProvider(AgentProviderEnum::from(strtolower($raw)));
        } catch (ValueError) {
            return self::forProvider(AgentProviderEnum::OPENCLAW);
        }
    }

    /**
     * Every runtime-capable provider — used when an operation has to fan out across runtimes
     * (writing channel tokens to every provider's custom-field set, rebuilding container
     * images on a machine that hosts multiple runtimes, etc.).
     *
     * @return list<AgentRuntimeProvider>
     */
    public static function runtimeProviders(): array
    {
        return array_map(
            self::forProvider(...),
            AgentProviderEnum::runtimeProviders(),
        );
    }
}
