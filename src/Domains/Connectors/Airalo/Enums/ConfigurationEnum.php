<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Airalo\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'ESIM_BASE_URL';
    case APP_TOKEN = 'ESIM_APP_TOKEN';
    case APP_CHANNEL_ID = 'ESIM_CHANNEL_ID';
    case PROVIDER_SLUG = 'product-provider';
    case VARIANT_PROVIDER_SLUG = 'variant-product-provider';
    case COMMERCE_API_KEY = 'WP-X-API-KEY';
}
