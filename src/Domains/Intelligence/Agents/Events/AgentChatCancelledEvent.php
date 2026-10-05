<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Events;

use Kanvas\Intelligence\Agents\Helpers\AgentChatBroadcastChannel;
use Override;

/**
 * The stop landed: not an error, so the client drops the typing state and leaves the person's
 * message in place to edit and resend.
 */
class AgentChatCancelledEvent extends AgentChatSessionEvent
{
    #[Override]
    public function broadcastAs(): string
    {
        return AgentChatBroadcastChannel::CANCELLED_EVENT;
    }
}
