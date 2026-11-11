<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Enums;

enum BillingScheduleStatusEnum: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case FINISHED = 'finished';
}
