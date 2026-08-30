<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * Whether an agent already said it, before its turn says it again.
 *
 * An agent can write on a channel mid-turn with a board tool and then have its final reply posted to
 * the same channel — two messages seconds apart, the second narrating the first. It has happened on
 * three different posting paths now (the PM's project wake, the plan worker, and the mention reply on
 * plan 26531), so the check lives here rather than in whichever job noticed it last.
 */
class AgentChannelActivity
{
    /**
     * The newest message on a channel — the baseline to compare against after the turn.
     */
    public static function latestMessageId(?Channel $channel): ?int
    {
        $latest = $channel?->messages()->orderByDesc('messages.id')->first();

        return $latest?->getId();
    }

    /**
     * Whether the agent put its own words on this channel since the baseline.
     *
     * Both halves of the test are needed. The payload stamp alone would count another agent posting
     * in the same seconds; the author alone would count a human, because agents share users with real
     * people — one production user backs 28 agents, and a PM writes as the same user as the person it
     * is talking to.
     */
    public static function agentPostedSince(?Channel $channel, ?int $sinceMessageId, ?Users $author): bool
    {
        if ($channel === null || $author === null) {
            return false;
        }

        return $channel->messages()
            ->when(
                $sinceMessageId !== null,
                fn ($query) => $query->where('messages.id', '>', $sinceMessageId),
            )
            ->where('messages.users_id', $author->getId())
            ->get()
            ->contains(fn (Message $message): bool => is_array($message->message)
                && (($message->message['from_agent'] ?? false) === true
                    || ($message->message['from_ia'] ?? false) === true));
    }
}
