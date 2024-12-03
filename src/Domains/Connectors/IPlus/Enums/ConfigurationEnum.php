<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\Enums;

enum ConfigurationEnum: string
{
    case AUTH_BASE_URL = 'I_PLUS_BASE_URL';
    case CLIENT_ID = 'I_PLUS_CLIENT_ID';
    case CLIENT_SECRET = 'I_PLUS_CLIENT_SECRET';
    case USERNAME = 'I_PLUS_USERNAME';
    case PASSWORD = 'I_PLUS_PASSWORD';
    case COMPANY_ID = 'I_PLUS_COMPANY_ID';
    case CUSTOMER_DEFAULT_REFERENCE = 'I_PLUS_CUSTOMER_DEFAULT_REFERENCE';
    case I_PLUS_REDIS_KEY_PREFIX = 'I_PLUS_REDIS_KEY_PREFIX_APP_';
}
