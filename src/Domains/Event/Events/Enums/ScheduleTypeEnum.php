<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Enums;

enum ScheduleTypeEnum: string
{
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
}
