<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AeroAmbulancia\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'AEROAMBULANCIA_API_BASE_URL';
    case EMAIL = 'AEROAMBULANCIA_EMAIL';
    case PASSWORD = 'AEROAMBULANCIA_PASSWORD';
    case SUBSCRIPTION_ID = 'AEROAMBULANCIA_SUBSCRIPTION_ID';
}
