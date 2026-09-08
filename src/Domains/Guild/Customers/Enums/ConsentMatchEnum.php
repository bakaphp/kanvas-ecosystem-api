<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Enums;

/**
 * How a consent signal was recognised. Recorded on the opt-out so a human reviewing one can tell an
 * unambiguous "STOP" from a phrase we inferred, and reverse the latter if we got it wrong.
 */
enum ConsentMatchEnum: string
{
    /** The whole message was one of the FCC keywords. No judgement involved. */
    case EXACT = 'exact';

    /** A do-not-contact phrase inside a longer sentence. Honored, but worth a human glance. */
    case PHRASE = 'phrase';

    /** The agent judged it a stop request. The loosest tier, and the one to review first. */
    case AGENT_TOOL = 'agent_tool';

    /**
     * Whether this was inferred rather than stated. An exact keyword is an explicit act by the
     * person and needs no second opinion; a phrase we pattern-matched and a judgement the agent made
     * are the two a human should be able to find and reverse.
     */
    public function warrantsReview(): bool
    {
        return match ($this) {
            self::EXACT => false,
            self::PHRASE, self::AGENT_TOOL => true,
        };
    }
}
