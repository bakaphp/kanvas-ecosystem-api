<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Enums;

enum CustomFieldEnum: string
{
    case MESSAGE_SID = 'twilio_message_sid';
    case CURRENT_STATUS = 'twilio_current_status';
    case LAST_ERROR_CODE = 'twilio_last_error_code';
    case LAST_ERROR_MESSAGE = 'twilio_last_error_message';
    case LAST_STATUS_AT = 'twilio_last_status_at';
}
