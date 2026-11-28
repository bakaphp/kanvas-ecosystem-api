<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Enums;

enum AppUserMailingStatusEnum: string
{
    case SCHEDULED = 'scheduled';
    case QUEUED = 'queued';
    case SENDING = 'sending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
