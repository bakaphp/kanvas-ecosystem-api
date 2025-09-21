<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Enums;

enum TimeSlotStatusEnum: string
{
    case OPEN = 'open';
    case HELD = 'held';
    case BOOKED = 'booked';
    case BLOCKED = 'blocked';
}
