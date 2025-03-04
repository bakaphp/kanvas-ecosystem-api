<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'CM_LINK_BASE_URL';
    case APP_KEY = 'CM_LINK_APP_KEY';
    case APP_SECRET = 'CM_LINK_APP_SECRET';
    case APP_TYPE = 'CM_LINK_APP_TYPE';
    case USE_CALENDAR_VARIANTS = 'CM_LINK_USE_CALENDAR_VARIANTS';
    case NAME = 'CMLink';
    case ICCID_INVENTORY_PRODUCT_TYPE = 'cmlink-iccid';
    case PRODUCT_FATHER_SKU = 'cmlink-father-sku';
}
