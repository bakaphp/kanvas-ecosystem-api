<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Enums;

enum ConfigurationEnum: string
{
    case NET_SUITE_ACCOUNT_CONFIG = 'NET_SUITE_ACCOUNT_CONFIG';
    case NET_SUITE_CUSTOM_API_URL = 'NET_SUITE_CUSTOM_API_URL';
    case NET_SUITE_MINIMUM_PRODUCT_QUANTITY = 'NET_SUITE_SET_B2B_MINIMUM_PRODUCT_QUANTITY';
}
