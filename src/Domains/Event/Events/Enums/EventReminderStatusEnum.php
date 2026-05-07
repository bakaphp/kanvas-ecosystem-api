<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Enums;

enum EventReminderStatusEnum: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
}
