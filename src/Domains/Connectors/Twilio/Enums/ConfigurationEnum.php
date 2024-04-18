<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Enums;

enum ConfigurationEnum: string
{
    case TWILIO_ACCOUNT_SID = 'TWILIO_ACCOUNT_SID';
    case TWILIO_AUTH_TOKEN = 'TWILIO_AUTH_TOKEN';
    case TWILIO_VERIFICATION_SID = 'TWILIO_VERIFICATION_SID';
}
