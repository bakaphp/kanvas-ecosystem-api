<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Square\Enums;

enum CustomFieldEnum: string
{
    case SQUARE_CUSTOMER_ID = 'SQUARE_CUSTOMER_ID';
    case SQUARE_ORDER_ID = 'SQUARE_ORDER_ID';
}
