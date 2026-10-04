<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\Stores\ConversationMessageStore;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use NeuronAI\Chat\History\MessageStoreInterface;
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
    protected function messageStore(): MessageStoreInterface
    {
        return $this->conversationStore();
    }

    /**
     * ConversationMessageStore already persists every turn (with usage + agent_id), so RunNeuronChatAction
     * must NOT also logTurn or every chat gets a duplicate conversation. Agents on the rollup store write
     * to Social messages instead and leave this false so logTurn stays their only usage record.
     */
    #[Override]
    public function persistsTurnsToConversationStore(): bool
    {
        return true;
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
