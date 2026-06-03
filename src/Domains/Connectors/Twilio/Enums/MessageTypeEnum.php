<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Enums;

enum MessageTypeEnum: string
{
    case SMS = 'twilio-sms';
}
