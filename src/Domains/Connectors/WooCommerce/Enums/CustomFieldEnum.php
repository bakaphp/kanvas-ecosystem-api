<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Enums;

enum CustomFieldEnum: string
{
    case WOOCOMMERCE_ID = 'woocommerce_id';
    case WOOCOMMERCE_ORDER_NUMBER = 'woocommerce_order_number';
}
