<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'WaSender';
    case BASE_URL = 'wasender_base_url';
    case API_KEY = 'wasender_api_key';
    case BASE_URL_OUTBOUND = 'wasender_base_url_outbound';
    // Account-level Personal Access Token — authorizes session management (create/list/delete
    // sessions). Distinct from API_KEY, which is a per-session key used for /api/send-message.
    case PERSONAL_ACCESS_TOKEN = 'wasender_personal_access_token';
}
