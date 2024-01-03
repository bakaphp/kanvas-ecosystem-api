<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Enums;

enum NotificationDistributionEnum: string
{
    case USERS = 'users';
    case FOLLOWERS = 'followers';
    case APP = 'app';
}
