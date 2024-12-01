<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Enums;

enum OrderStatusEnum: string
{
    case COMPLETED = 'completed';
    case DRAFT = 'draft';
    case CANCELED = 'canceled';
}
