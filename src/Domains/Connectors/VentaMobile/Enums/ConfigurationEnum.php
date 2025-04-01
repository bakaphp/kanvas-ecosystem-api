<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Enums;

enum ConfigurationEnum: string
{
    case USE_CALENDAR_VARIANTS = 'VENTA_MOBILE_USE_CALENDAR_VARIANTS';
    case NAME = 'VentaMobile';
    case ICCID_INVENTORY_PRODUCT_TYPE = 'venta-mobile-iccid';
    case PRODUCT_FATHER_SKU = 'venta-mobile-father-sku';
    case ICCID_ACTIVATION_LINK = 'iccid-activation-link';
}
