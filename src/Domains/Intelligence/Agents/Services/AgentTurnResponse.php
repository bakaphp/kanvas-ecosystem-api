<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

/**
 * How an agent says "nothing to add" without saying anything.
 *
 * A model asked to reply always replies — "Understood.", "Standing by." — and on a channel two agents
 * share, each of those is a message the other one answers. The sentinel is the opt-out, and it only
 * works if every path that posts a turn recognises it: `WakeAgentForProjectJob` did, the mention path
 * did not, so a PM's NO_UPDATE was posted verbatim and answered twice (plan 20355).
 */
class AgentTurnResponse
{
    public const string NO_UPDATE = 'NO_UPDATE';

    /**
     * Nothing worth posting: an empty turn, or the sentinel — allowing for a model wrapping it in
     * markdown, quotes or punctuation, which they reliably do.
     */
    public static function isNoOp(string $response): bool
    {
        $normalized = strtoupper(trim($response, " \t\n\r\0\x0B*#`.\"'"));

        return $normalized === '' || str_starts_with($normalized, self::NO_UPDATE);
    }

    /**
     * The instruction that makes the sentinel usable, for a turn where silence is a valid answer.
     *
     * Only ever given when the counterpart is another AGENT. A person who asks a question and gets
     * silence has been ignored; an agent that acknowledges an acknowledgement is a loop.
     */
    public static function noOpGuidance(): string
    {
        return "\n\n---\nIf you have nothing to add — the other agent is acknowledging you, confirming "
            . 'something you already know, or the exchange is finished — reply with exactly '
            . self::NO_UPDATE . ' and nothing else. Do not reply out of politeness: an acknowledgement '
            . 'answered by an acknowledgement is a loop that costs a turn each time. Answer only when '
            . 'you are adding information, asking for something, or reporting a change.';
    }
}
