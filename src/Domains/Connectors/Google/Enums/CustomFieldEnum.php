<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Enums;

enum CustomFieldEnum: string
{
    case GOOGLE_INTERACTION_NAME = 'google-interaction-name';
    case GOOGLE_CALENDAR_EVENT_ID = 'google-calendar-event-id';
    case GOOGLE_CALENDAR_HTML_LINK = 'google-calendar-html-link';
    case GOOGLE_CALENDAR_MEET_LINK = 'google-calendar-meet-link';
}
