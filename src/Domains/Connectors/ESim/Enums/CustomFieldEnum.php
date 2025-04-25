<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Enums;

enum CustomFieldEnum: string
{
    case ORDER_ESIM_METADATA = 'order_esim_metadata';
    case MESSAGE_ESIM_ID = 'message_esim_id';
    case PRODUCT_ESIM_ID = 'product_esim_id';
    case VARIANT_ESIM_ID = 'variant_esim_id';
    case WOOCOMMERCE_ORDER_ID = 'woocommerce_id';
}
