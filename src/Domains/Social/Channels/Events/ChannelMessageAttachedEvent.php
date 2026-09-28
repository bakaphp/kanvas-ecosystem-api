<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class ChannelMessageAttachedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Channel $channel,
        public readonly Message $message,
    ) {
    }
}
