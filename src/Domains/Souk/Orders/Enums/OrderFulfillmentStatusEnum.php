<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Enums;

enum OrderFulfillmentStatusEnum: string
{
    case COMPLETED = 'fulfilled';
    case PENDING = 'pending';
    case CANCELLED = 'canceled';
}
