<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Enums;

enum CustomFieldEnum: string
{
    case PARENT_DISCOUNT_ID = 'parent_discount_id';
    case SOURCE_ORDER_ID = 'source_order_id';
}
