<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Enums;

enum RefundReasonEnum: string
{
    case DUPLICATE = 'duplicate';
    case FRAUDULENT = 'fraudulent';
    case REQUESTED_BY_CUSTOMER = 'requested_by_customer';
    case OUT_OF_STOCK = 'out_of_stock';
    case OTHER = 'other';
}
