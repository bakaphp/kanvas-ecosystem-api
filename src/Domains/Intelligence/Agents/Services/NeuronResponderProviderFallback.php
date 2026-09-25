<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Models\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Router\RouterProvider;
use Throwable;

/** Build a transient-error failover chain from the tenant's agent_llm_configs. */
final class NeuronResponderProviderFallback
{
    public function wrap(AIProviderInterface $primary, Agent $agent): AIProviderInterface
    {
        if ($primary instanceof RouterProvider
            || ($agent->config['provider_fallback_enabled'] ?? true) === false) {
            return $primary;
        }

        $router = RouterProvider::make()->addProvider('primary', $primary);
        $order = ['primary'];

        foreach (AgentProviderService::fallbackConfigs($agent) as $config) {
            try {
                $name = 'llm_config_' . $config->getId();
                $router->addProvider($name, AgentProviderService::resolveConfig($agent, $config));
                $order[] = $name;
            } catch (Throwable $e) {
                Log::warning('Skipping invalid LLM fallback config', [
                    'agent_id' => $agent->getId(),
                    'llm_config_id' => $config->getId(),
                    'provider' => $config->provider,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return count($order) === 1
            ? $primary
            : $router->setFallbackOrder(...$order);
    }
}
