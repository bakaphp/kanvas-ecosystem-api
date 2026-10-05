<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use NeuronAI\Chat\History\MessageStoreInterface;
use Override;

/**
 * The memory surface of a ConversesWithCustomer agent: one timeline per prospect, rolled up across
 * every channel and remembered across sessions, and nothing beyond it. Requires the
 * HasKanvasAgentBehavior properties.
 */
trait HasProspectIsolatedHistory
{
    #[Override]
    protected function messageStore(): MessageStoreInterface
    {
        return $this->entityRollupStore($this->sessionThreadId());
    }

    /**
     * The agent remembers and improves with the customer: every turn is written to memory tagged with
     * the record, and recall is limited to that record by recordMemoryScope().
     */
    #[Override]
    protected function remembersForCompany(): bool
    {
        return true;
    }
}
