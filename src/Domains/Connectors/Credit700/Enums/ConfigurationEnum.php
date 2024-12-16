<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Enums;

enum ConfigurationEnum: string
{
    case ACCOUNT = '700CREDIT_ACCOUNT';
    case PASSWORD = '700CREDIT_PASSWORD';
    case CLIENT_ID = '700CREDIT_CLIENT_ID';
    case CLIENT_SECRET = '700CREDIT_CLIENT_SECRET';
    case ACTION_VERB = '700-credit';
}
