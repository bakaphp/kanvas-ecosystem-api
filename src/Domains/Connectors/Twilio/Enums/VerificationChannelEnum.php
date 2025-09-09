<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Enums;

enum VerificationChannelEnum: string
{
    case SMS = 'sms';
    case EMAIL = 'email';
}
