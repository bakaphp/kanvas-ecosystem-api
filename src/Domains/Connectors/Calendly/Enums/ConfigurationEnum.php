<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Calendly\Enums;

enum ConfigurationEnum: string
{
    case CALENDLY_API_TOKEN = 'CALENDLY_API_TOKEN';
    case CALENDLY_ORGANIZATION_URI = 'CALENDLY_ORGANIZATION_URI';
    case CALENDLY_USER_URI = 'CALENDLY_USER_URI';
    case CALENDLY_WEBHOOK_SUBSCRIPTION_URI = 'CALENDLY_WEBHOOK_SUBSCRIPTION_URI';
}
