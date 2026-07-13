<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Social\Messages\Models\Message;

class MessageMentionsStoredEvent
{
    use Dispatchable;

    /**
     * @param list<int> $mentionedUserIds
     */
    public function __construct(
        public readonly Message $message,
        public readonly array $mentionedUserIds,
    ) {
    }
}
