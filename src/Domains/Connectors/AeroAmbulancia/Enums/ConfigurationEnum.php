<?php

declare(strict_types=1);

namespace Kanvas\Domains\Connectors\AeroAmbulancia\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'AEROAMBULANCIA_API_BASE_URL';
    case EMAIL = 'AEROAMBULANCIA_EMAIL';
    case PASSWORD = 'AEROAMBULANCIA_PASSWORD';
    case PROVIDER_SLUG = 'aeroambulancia';
    case VARIANT_PROVIDER_SLUG = 'variant-provider-aeroambulancia';
    case APP_CHANNEL_ID = 'aeroambulancia_channel_id';
}
