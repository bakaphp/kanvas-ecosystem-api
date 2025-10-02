<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Enums;

enum EmailTemplateEnum: string
{
    case PARTICIPANT_NOTIFICATION = 'participant_notification';
    case BOOKING_CREATED = 'booking_created';
    case BOOKING_UPDATED = 'booking_updated';
    case BOOKING_CANCELLED = 'booking_cancelled';
}
