<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Enums;

enum FollowUpModeEnum: string
{
    case TIME_BASED = 'time_based';
    case GOAL_BASED = 'goal_based';
}
