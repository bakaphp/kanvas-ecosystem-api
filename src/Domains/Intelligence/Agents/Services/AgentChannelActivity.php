<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * What an agent has said on a channel — the questions every posting path has to ask before speaking.
 *
 * Three of them post an agent's turn (the project wake, the plan worker, the mention reply), and each
 * needs the same two answers: did a machine write this, and has this agent already said its piece here
 * during the turn. Kept together so the paths cannot drift apart on what counts.
 */
class AgentChannelActivity
{
    /**
     * Whether a machine wrote this, read off the message rather than inferred from its author.
     *
     * Author identity cannot answer it: agents share users with real people — one production user
     * backs 28 agents — so `Agent::fromUser()` calls a human's message agent-authored. Both stamps
     * count; a board comment carries `from_agent`, a turn reply carries `from_ia`.
     */
    public static function isAgentAuthored(?Message $message): bool
    {
        $payload = $message?->message;

        return is_array($payload)
            && (($payload['from_agent'] ?? false) === true || ($payload['from_ia'] ?? false) === true);
    }

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
                fn (Builder $query): Builder => $query->where('messages.id', '>', $sinceMessageId),
            )
            ->where('messages.users_id', $author->getId())
            ->get()
            ->contains(fn (Message $message): bool => self::isAgentAuthored($message));
    }
}
