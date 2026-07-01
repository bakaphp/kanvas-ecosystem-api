<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Enums;

enum ConfigurationEnum: string
{
    case WEBHOOK_SIGNING_KEY = 'MAILGUN_WEBHOOK_SIGNING_KEY';
    case API_KEY = 'MAILGUN_API_KEY';
    case LAST_VALIDATED_AT = 'MAILGUN_LAST_VALIDATED_AT';
}
