<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts;

use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;

/**
 * For a provider whose `accounts()` names, for each account, the agent holding its number. Only
 * such a provider has anything to reconcile when an agent takes or gives up a number.
 */
interface ReadsAccountOwnerFromAgents
{
    /**
     * @return list<ChannelTypeEnum> the channels whose number on the agent decides the owner
     */
    public static function agentOwnedChannels(): array;
}
