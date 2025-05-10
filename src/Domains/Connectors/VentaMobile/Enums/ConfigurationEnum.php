<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'VentaMobile';
    case BASE_URL = 'venta_mobile_base_url';
    case USERNAME = 'venta_mobile_username';
    case PASSWORD = 'venta_mobile_password';
    case USE_CALENDAR_VARIANTS = 'CM_LINK_USE_CALENDAR_VARIANTS';
    case ICCID_INVENTORY_PRODUCT_TYPE = 'venta-mobile-iccid';
    case PRODUCT_FATHER_SKU = 'cmlink-father-sku';
    case PRODUCT_REFUEL_SKU = 'refueling-package';
}
