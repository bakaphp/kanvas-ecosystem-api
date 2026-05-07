<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'WaSender';
    case BASE_URL = 'wasender_base_url';
    case API_KEY = 'wasender_api_key';
    case BASE_URL_OUTBOUND = 'wasender_base_url_outbound';
}
