<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Enums;

enum ShippingConditionEnum: string
{
    case NAME = 'Shipping';
    case TYPE = 'shipping';
    case TARGET = 'subtotal';
    case PROVIDER = 'provider';
    case SERVICE = 'service';
    case SERVICE_NAME = 'service_name';
    case METHOD_NAME = 'method_name';
    case ESTIMATE_SHIPPING_DATE = 'estimate_shipping_date';
    case DESTINATION = 'destination';
    case QUOTED_AMOUNT = 'quoted_amount';
    case CURRENCY = 'currency';
    case QUOTED_AT = 'quoted_at';
}
