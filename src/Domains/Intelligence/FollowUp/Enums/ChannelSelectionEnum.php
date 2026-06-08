<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Enums;

enum ChannelSelectionEnum: string
{
    case STICKY_THEN_PRIORITY = 'sticky_then_priority';
    case PRIORITY_ONLY = 'priority_only';
    case AGENT_PICKS = 'agent_picks';
    case FAN_OUT_ALL = 'fan_out_all';
}
