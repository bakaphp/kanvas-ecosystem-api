<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use NeuronAI\Chat\History\MessageStoreInterface;
use Override;

/**
 * The memory surface of a ConversesWithCustomer agent: one timeline per prospect, rolled up across
 * every channel, and nothing beyond it. Requires the HasKanvasAgentBehavior properties.
 */
trait HasProspectIsolatedHistory
{
    #[Override]
    protected function messageStore(): MessageStoreInterface
    {
        return $this->entityRollupStore($this->sessionThreadId());
    }
}
