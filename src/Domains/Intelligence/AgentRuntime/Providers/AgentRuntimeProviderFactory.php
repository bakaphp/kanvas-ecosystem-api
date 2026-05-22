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

    // Used at launch time when no deployment row exists yet. Falls back to OPENCLAW for
    // legacy agent types that pre-date the `agent_types.provider` column.
    public static function forAgent(Agent $agent): AgentRuntimeProvider
    {
        $raw = $agent->type?->provider;

        if (! is_string($raw) || $raw === '') {
            return self::forProvider(AgentProviderEnum::OPENCLAW);
        }

        try {
            return self::forProvider(AgentProviderEnum::from(strtolower($raw)));
        } catch (ValueError) {
            return self::forProvider(AgentProviderEnum::OPENCLAW);
        }
    }

    /** @return list<AgentRuntimeProvider> */
    public static function runtimeProviders(): array
    {
        return array_values(array_map(
            self::forProvider(...),
            AgentProviderEnum::runtimeProviders(),
        ));
    }
}
