<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

/**
 * Why a month produced no update. The two cases look identical from outside and need opposite
 * responses: nothing shipped is a waiting game, the agent declining is a signal the account has no
 * usable notes on it.
 */
enum CustomerUpdateSkipEnum: string
{
    case NO_RELEASES = 'no_releases';
    case AGENT_DECLINED = 'agent_declined';
}
