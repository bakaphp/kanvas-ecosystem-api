<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Enums;

enum CustomFieldEnum: string
{
    case PURCHASE_ID = 'cardnet_purchase_id';
    case AUTHORIZATION_CODE = 'cardnet_authorization_code';
    case TOKEN = 'cardnet_token';
    case CUSTOMER_ID = 'cardnet_customer_id';
    case ORDER_NUMBER = 'cardnet_order_number';
}
