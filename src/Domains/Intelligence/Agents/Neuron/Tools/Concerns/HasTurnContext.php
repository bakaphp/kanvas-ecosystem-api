<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

trait HasTurnContext
{
    protected ?string $turnThreadId = null;
    protected ?string $turnId = null;

    public function withTurn(?string $threadId, ?string $turnId): static
    {
        $this->turnThreadId = $threadId;
        $this->turnId = $turnId;

        return $this;
    }

    protected function hasTurnContext(): bool
    {
        return $this->turnThreadId !== null && $this->turnId !== null;
    }
}
