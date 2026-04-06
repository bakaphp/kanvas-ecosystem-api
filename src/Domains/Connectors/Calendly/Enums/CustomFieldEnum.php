<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Calendly\Enums;

enum CustomFieldEnum: string
{
    case CALENDLY_EVENT_URI = 'CALENDLY_EVENT_URI';
    case CALENDLY_INVITEE_URI = 'CALENDLY_INVITEE_URI';
    case CALENDLY_EVENT_TYPE = 'CALENDLY_EVENT_TYPE';
    case CALENDLY_EVENT_NAME = 'CALENDLY_EVENT_NAME';
}
