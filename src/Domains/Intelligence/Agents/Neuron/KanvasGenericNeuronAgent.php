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
            // userChat sets threadId (=session uuid); the channel path leaves it null but carries a
            // stable session — either way this keys the conversation to one thread per session.
            sessionId: $this->threadId ?? $this->session?->uuid,
            agent: $this->agent,
            turnMedia: $this->turnMedia,
            model: $this->resolvedModelName(),
        );
    }

    /**
     * KanvasMessageHistory already persists every turn (with usage + agent_id) to the conversation
     * store, so RunNeuronChatAction must NOT also logTurn — that's what produced a duplicate
     * conversation per chat. Agents whose history writes elsewhere (SalesAssist → Social messages)
     * leave this false so logTurn stays their only usage record.
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
