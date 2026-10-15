<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Illuminate\Support\Facades\Cache;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * Finds the Kanvas row for a Slack message by its timestamp, which is Slack's message id. Needed
 * twice per inbound event: to drop the second delivery of a tagged message (Slack sends it as both
 * `app_mention` and `message`), and to hang a thread reply under its root.
 *
 * The timestamp only lives inside the message JSON, and that lookup walks every row of the channel.
 * A cache in front answers the common cases — the double delivery seconds apart, a reply in a live
 * thread — so the JSON walk is left for old roots. Scoped to the Kanvas channel, never the table.
 */
class SlackMessageLookupService
{
    private const int TTL_SECONDS = 7 * 24 * 3600;

    public function find(Channel $channel, string $ts): ?Message
    {
        if ($ts === '') {
            return null;
        }

        $cached = Cache::get($this->key($channel, $ts));

        if (is_int($cached)) {
            $message = Message::query()->whereKey($cached)->where('is_deleted', 0)->first();

            if ($message !== null) {
                return $message;
            }
        }

        // Edits are stored as further rows with the same timestamp; the lowest id is the original.
        $message = $channel->messages()
            ->where('messages.message->message_id', $ts)
            ->where('messages.is_deleted', 0)
            ->orderBy('messages.id')
            ->first();

        if ($message !== null) {
            $this->remember($channel, $ts, $message);
        }

        return $message;
    }

    public function remember(Channel $channel, string $ts, Message $message): void
    {
        if ($ts === '') {
            return;
        }

        Cache::add($this->key($channel, $ts), $message->getId(), self::TTL_SECONDS);
    }

    private function key(Channel $channel, string $ts): string
    {
        return 'slack:message:' . $channel->getId() . ':' . $ts;
    }
}
