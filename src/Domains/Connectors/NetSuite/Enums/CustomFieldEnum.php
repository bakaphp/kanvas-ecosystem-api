<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Enums;

enum CustomFieldEnum: string
{
    case NET_SUITE_CUSTOMER_ID = 'NET_SUITE_CUSTOMER_ID';
    case NET_SUITE_LOCATION_ID = 'NET_SUITE_LOCATION_ID';
    case NET_SUITE_MAP_PRICE_CUSTOM_FIELD = 'custitem40';
    case NET_SUITE_COLOR_CODE_CUSTOM_FIELD = 'custitem15';
}
