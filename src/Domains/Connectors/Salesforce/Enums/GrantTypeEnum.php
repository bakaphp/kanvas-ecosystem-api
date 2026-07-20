<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Enums;

enum GrantTypeEnum: string
{
    case REFRESH_TOKEN = 'refresh_token';
    case CLIENT_CREDENTIALS = 'client_credentials';
}
