<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

#[AgentTypeDefinition(
    name: 'Generic Neuron Agent',
    description: 'Generic agent using the NeuronAI runtime — same persona, alternate runtime. Use to A/B the same prompt across both engines.',
    provider: 'neuron',
    soul: 'You are a helpful, concise assistant running inside Kanvas via the NeuronAI runtime.',
    outputFormat: 'Plain text. Use short paragraphs; use lists only when enumerating distinct items.',
)]
class KanvasGenericNeuronAgent extends BaseKanvasAgent
{
    use MergesRegisteredTools;

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

    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        return $this->resolveRegisteredTools(
            $this->agent,
            CapabilityFrameworkEnum::NEURON
        );
    }
}
