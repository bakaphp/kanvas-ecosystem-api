<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence\Concerns;

use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use Override;

/**
 * An agent stub that keeps its history in memory and carries no tools, so a turn touches neither the
 * database nor a provider's tool loop.
 */
trait RunsOffline
{
    #[Override]
    protected function messageStore(): MessageStoreInterface
    {
        return new InMemoryMessageStore();
    }

    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        return [];
    }
}
