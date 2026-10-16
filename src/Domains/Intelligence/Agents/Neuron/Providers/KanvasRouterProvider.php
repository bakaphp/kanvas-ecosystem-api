<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Router\RouterProvider;
use Override;

/**
 * neuron-core/router 2.1.0 accepts a SystemMessage in systemPrompt() but stores it in a `?string`
 * property, so the first turn through a failover chain dies with a TypeError once Neuron 4 hands it
 * the agent's SystemMessage. The prompt is kept here as given, message and all, and handed to each
 * provider the same way.
 */
final class KanvasRouterProvider extends RouterProvider
{
    private SystemMessage|string|null $instructions = null;

    #[Override]
    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->instructions = $prompt;

        return $this;
    }

    #[Override]
    protected function prepare(string $name): AIProviderInterface
    {
        return $this->providers[$name]
            ->systemPrompt($this->instructions)
            ->setTools($this->tools);
    }
}
