<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio\Enums;

enum MessageTypeEnum: string
{
    case SMS = 'twilio-sms';
}
