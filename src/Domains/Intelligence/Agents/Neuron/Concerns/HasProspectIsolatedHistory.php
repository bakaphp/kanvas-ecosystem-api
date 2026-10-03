<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

/**
 * The memory surface of a ConversesWithCustomer agent: one timeline per prospect, rolled up across
 * every channel, and nothing beyond it. Requires the HasKanvasAgentBehavior properties.
 */
trait HasProspectIsolatedHistory
{
    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->entity === null || $this->user === null) {
            return new InMemoryChatHistory();
        }

        return new SalesAssistKanvasMessageHistory(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            entity: $this->entity,
            threadId: $this->threadId,
            currentLead: $this->currentLead,
            contextWindow: $this->resolvedContextWindow(),
        );
    }
}
