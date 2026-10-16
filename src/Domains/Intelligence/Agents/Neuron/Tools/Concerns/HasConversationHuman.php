<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Kanvas\Users\Models\Users;

/** The trusted conversation caller, separate from the agent's execution identity. */
trait HasConversationHuman
{
    protected ?Users $conversationHuman = null;

    public function forConversationHuman(?Users $user): static
    {
        $this->conversationHuman = $user;

        return $this;
    }
}
