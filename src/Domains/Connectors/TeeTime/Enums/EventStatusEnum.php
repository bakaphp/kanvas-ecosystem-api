<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TeeTime\Enums;

enum EventStatusEnum: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case VALIDATED = 'validated';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
