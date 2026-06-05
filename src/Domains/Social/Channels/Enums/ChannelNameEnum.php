<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Enums;

enum ChannelNameEnum: string
{
    case DEFAULT = 'Default Channel';
    case NOTES = 'Notes';
    case AI_ASSIST = 'AI Assist';
    case ACTIVITIES = 'Activities';
}
