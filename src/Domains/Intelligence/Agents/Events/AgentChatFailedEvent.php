<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Events;

use Kanvas\Intelligence\Agents\Helpers\AgentChatBroadcastChannel;
use Override;

/**
 * A queued turn that died writes no message, so this is the only signal the chat is over —
 * without it the client spins forever.
 */
class AgentChatFailedEvent extends AgentChatSessionEvent
{
    #[Override]
    public function broadcastAs(): string
    {
        return AgentChatBroadcastChannel::FAILED_EVENT;
    }
}
