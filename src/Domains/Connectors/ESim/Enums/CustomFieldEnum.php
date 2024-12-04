<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Enums;

enum CustomFieldEnum: string
{
    case ORDER_ESIM_METADATA = 'order_esim_metadata';
    case PRODUCT_ESIM_ID = 'product_esim_id';
    case VARIANT_ESIM_ID = 'variant_esim_id';
}
