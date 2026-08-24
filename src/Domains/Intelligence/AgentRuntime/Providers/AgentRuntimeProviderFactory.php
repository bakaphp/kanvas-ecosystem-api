<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Providers;

use Kanvas\Connectors\ClaudeAgent\Providers\ClaudeProvider;
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
            AgentProviderEnum::CLAUDE => new ClaudeProvider(),
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

    // Canonical resolution for every "chat with this agent" path: the active deployment's
    // provider when one is running, the agent type's declared provider otherwise. Both
    // RunRuntimeChatAction and RuntimeAgentChannelResponderAction must go through this so a
    // chat always reaches the same runtime regardless of which entry point triggered it.
    public static function forRunningAgent(Agent $agent): AgentRuntimeProvider
    {
        $deployment = $agent->activeDeployment;

        return $deployment instanceof AgentDeployment
            ? self::forDeployment($deployment)
            : self::forAgent($agent);
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
