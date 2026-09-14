<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Kanvas\Social\Channels\Models\Channel;
use Throwable;

/**
 * A ceiling on how long two agents may talk to each other on one channel.
 *
 * Two agents answering each other is a cycle with no natural end — a person stops replying, agents do
 * not. Budgeting the THREAD was not enough: on plan 20355 the pair spent all six hops, posted the stop
 * notice, and then carried straight on under a new root, because a new thread meant a new counter. The
 * budget has to sit above the thread or it is only ever a speed bump.
 *
 * A human speaking on the channel RESETS it. That is the difference between a runaway loop and a
 * conversation: a person re-entering is the signal that the exchange is wanted, and it means nobody
 * has to wait out a cooldown or find an admin to clear a counter.
 */
class AgentConversationBudget
{
    /** Enough for "how is it going / here is the status / then do X"; short of a debate. */
    public const int MAX_HOPS = 6;

    private const string FIELD = 'AGENT_CONVERSATION_HOPS';

    private const string STOPPED_FIELD = 'AGENT_CONVERSATION_STOPPED';

    /**
     * Charge one agent-to-agent hop. False when the budget is spent — the caller must then wake nobody.
     */
    public static function spend(?Channel $channel): bool
    {
        if ($channel === null) {
            return true;
        }

        $used = self::used($channel);

        if ($used >= self::MAX_HOPS) {
            return false;
        }

        try {
            $channel->set(self::FIELD, $used + 1);
        } catch (Throwable) {
            // A counter we cannot persist would read as zero forever, so refuse the hop rather than
            // hand back an unbounded yes.
            return false;
        }

        return true;
    }

    public static function used(?Channel $channel): int
    {
        if ($channel === null) {
            return 0;
        }

        try {
            return (int) ($channel->get(self::FIELD) ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * A person spoke here — the exchange is wanted, so the agents get their budget back.
     */
    public static function reset(?Channel $channel): void
    {
        if ($channel === null) {
            return;
        }

        try {
            $channel->set(self::FIELD, 0);
            $channel->set(self::STOPPED_FIELD, 0);
        } catch (Throwable) {
            // Nothing to do: a reset that fails leaves the pair capped, which is the safe direction.
        }
    }

    /**
     * Whether this caller is the one that should announce the stop.
     *
     * Every refused hop would otherwise post its own notice — plan 20355 collected three. True exactly
     * once per exhausted channel, until a human resets it.
     */
    public static function claimStopNotice(?Channel $channel): bool
    {
        if ($channel === null) {
            return false;
        }

        try {
            if ((int) ($channel->get(self::STOPPED_FIELD) ?? 0) === 1) {
                return false;
            }

            $channel->set(self::STOPPED_FIELD, 1);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
