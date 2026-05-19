<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;
use ReflectionClass;

class KanvasGenericNeuronAgent extends BaseKanvasAgent
{
    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->user === null || $this->app === null || $this->company === null) {
            return new InMemoryChatHistory();
        }

        return new KanvasMessageHistory(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            agentClass: static::class,
            conversationId: $this->threadId,
        );
    }

    #[Override]
    protected function tools(): array
    {
        if ($this->agent === null) {
            return [];
        }

        $tools = [];

        foreach (new CapabilityProvider()->getActiveTools($this->agent) as $tool) {
            /** @var Tool $tool */
            if ($tool->handler === null || ! class_exists($tool->handler)) {
                continue;
            }

            $ref = new ReflectionClass($tool->handler);
            $ctor = $ref->getConstructor();
            $hasRequired = $ctor && array_filter(
                $ctor->getParameters(),
                fn ($p) => ! $p->isOptional()
            );

            if ($hasRequired) {
                continue;
            }

            $tools[] = new $tool->handler();
        }

        return $tools;
    }
}
