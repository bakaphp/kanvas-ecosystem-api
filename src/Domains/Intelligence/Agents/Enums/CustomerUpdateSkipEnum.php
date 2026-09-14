<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

/**
 * Why a month produced no update. They look identical from outside and need opposite responses:
 * nothing shipped is a waiting game, everything already covered is the normal steady state, and the
 * agent declining is a signal the account has no usable notes on it.
 *
 * Only AGENT_DECLINED costs an LLM turn — the other two are decided before the agent is asked, which
 * is the point of having them separate.
 */
enum CustomerUpdateSkipEnum: string
{
    case NO_RELEASES = 'no_releases';
    case ALREADY_COVERED = 'already_covered';
    case AGENT_DECLINED = 'agent_declined';

    /** Whether reaching this skip meant paying for a turn. */
    public function costAnLlmTurn(): bool
    {
        return $this === self::AGENT_DECLINED;
    }
}
