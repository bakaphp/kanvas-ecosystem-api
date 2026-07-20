<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Enums;

enum ConfigurationEnum: string
{
    case CLIENT_ID = 'salesforce_client_id';
    case CLIENT_SECRET = 'salesforce_client_secret';
    case REFRESH_TOKEN = 'salesforce_refresh_token';
    case LOGIN_URL = 'salesforce_login_url';
    case API_VERSION = 'salesforce_api_version';
}
