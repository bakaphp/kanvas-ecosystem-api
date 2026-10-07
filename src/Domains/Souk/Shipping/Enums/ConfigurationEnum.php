<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Enums;

enum ConfigurationEnum: string
{
    case DEFAULT_BOX_CM = 'shipping_default_box_cm';
    case FALLBACK_DESTINATION_COUNTRY = 'shipping_fallback_destination_country';
}
