<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Events;

class ChannelMessageRemovedEvent extends ChannelMessageChangedEvent
{
    public function broadcastAs(): string
    {
        return 'message.channel.removed';
    }
}
