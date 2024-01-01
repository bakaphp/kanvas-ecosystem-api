<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Enums;

enum NotificationDistributionEnum: string
{
    case ONE = 'one';
    case FOLLOWERS = 'followers';
    case APP = 'app';
}
