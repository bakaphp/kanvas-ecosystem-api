<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gmail\Enums;

enum ConfigurationEnum: string
{
    case CLIENT_ID = 'gmail-client-id';
    case CLIENT_SECRET = 'gmail-client-secret';
    case REFRESH_TOKEN = 'gmail-refresh-token';
}
