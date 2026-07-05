<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Social\Messages\Models\Message;

/**
 * A message's @mentions were resolved and stored. Domains that care about specific
 * mentioned users (e.g. Intelligence, when an agent-user is mentioned) listen for this
 * — Social owns the parsing, consumers own what to do about it.
 */
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
