<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Airalo\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'AIRALO_API_BASE_URL';
    case BASE_URL_V2 = 'AIRALO_API_BASE_URL_V2';
    case CLIENT_ID = 'AIRALO_CLIENT_ID';
    case CLIENT_SECRET = 'AIRALO_CLIENT_SECRET';
    case GRANT_TYPE = 'AIRALO_GRANT_TYPE';
    case COUNTRIES = 'AIRALO_COUNTRIES';
    case PROVIDER_SLUG = 'airalo';
    case VARIANT_PROVIDER_SLUG = 'variant-provider-airalo';
    case APP_CHANNEL_ID = 'airalo_channel_id';
    case TEST_ACCESS_TOKEN = 'AIRALO_TEST_ACCESS_TOKEN';
}
