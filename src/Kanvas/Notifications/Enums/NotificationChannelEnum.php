<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Enums;

enum NotificationChannelEnum: int
{
    case MAIL = 1;
    case PUSH = 2;
    case REALTIME = 3;
    case SMS = 4;
}
