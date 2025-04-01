<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OrionMobile\Enums;

enum ConfigurationEnum: string
{
    case USE_CALENDAR_VARIANTS = 'ORION_MOBILE_USE_CALENDAR_VARIANTS';
    case NAME = 'OrionMobile';
    case ICCID_INVENTORY_PRODUCT_TYPE = 'orion-mobile-iccid';
    case PRODUCT_FATHER_SKU = 'orion-mobile-father-sku';
    case ICCID_ACTIVATION_LINK = 'iccid-activation-link';
}
