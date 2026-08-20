<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

use Kanvas\Connectors\WaSender\Traits\ReadsReceiverConfigurationTrait;
use Override;

/**
 * Group-room behaviour on the receiver. Two axes that are never one setting: an allow-listed group
 * is always ingested and always processed, and `group_reply_mode` decides only whether the agent
 * speaks back into it. Burst timing and media policy are connection-wide — see BurstConfigEnum.
 */
enum GroupConfigEnum: string
{
    use ReadsReceiverConfigurationTrait;

    /**
     * Opt-in. An empty list ingests no group at all — a phone sits in dozens of noisy groups and
     * one album alone produced seven messages in forty seconds in the capture.
     */
    case ALLOWED_GROUP_JIDS = 'allowed_group_jids';

    case GROUP_AGENT_ID = 'group_agent_id';
    case GROUP_REPLY_MODE = 'group_reply_mode';

    #[Override]
    public function default(): mixed
    {
        return match ($this) {
            self::ALLOWED_GROUP_JIDS => [],
            self::GROUP_AGENT_ID => null,
            self::GROUP_REPLY_MODE => GroupReplyModeEnum::MENTION->value,
        };
    }
}
