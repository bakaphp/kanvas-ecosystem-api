<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Events;

class ChannelMessageUpdatedEvent extends ChannelMessageChangedEvent
{
    public function broadcastAs(): string
    {
        return 'message.channel.updated';
    }
}
