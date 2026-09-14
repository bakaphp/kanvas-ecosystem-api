<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Enums;

/**
 * How a consent signal was recognised. Recorded on the opt-out so a human reviewing one can tell an
 * unambiguous "STOP" from something we inferred, and reverse the latter if we got it wrong.
 */
enum ConsentMatchEnum: string
{
    /** The whole message was an unambiguous FCC keyword. No judgement involved. */
    case EXACT = 'exact';

    /**
     * The whole message was an FCC keyword that is ALSO ordinary conversation — "cancel", "end".
     * Applied like any other keyword, because the FCC requires honoring the whole set, but flagged:
     * a person cancelling an appointment writes the same word as a person revoking consent.
     */
    case AMBIGUOUS_KEYWORD = 'ambiguous_keyword';

    /** A do-not-contact phrase inside a longer sentence. Honored, but worth a human glance. */
    case PHRASE = 'phrase';

    /** The agent judged it a stop request. The loosest tier, and the one to review first. */
    case AGENT_TOOL = 'agent_tool';

    /**
     * What to tell the human reading the lead note, or null when the match speaks for itself.
     *
     * This IS the review mechanism — an opt-out is person-wide and re-subscribing only reopens the
     * address the START came from, so a wrong call is not something the customer can undo. Whatever
     * a reviewer needs in order to spot one has to be in words, on the note.
     */
    public function reviewNote(): ?string
    {
        return match ($this) {
            self::EXACT => null,
            self::AMBIGUOUS_KEYWORD => ' The message was a single word that is an FCC opt-out keyword but is also '
                . 'ordinary conversation — honored as the rules require, but check they were not cancelling '
                . 'something else.',
            self::PHRASE => ' Inferred from a phrase rather than an explicit keyword — review if this looks wrong.',
            self::AGENT_TOOL => ' The agent judged this to be a stop request — review if this looks wrong.',
        };
    }
}
