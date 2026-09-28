<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Enums;

enum CampaignStatusEnum: string
{
    case SCHEDULED = 'scheduled';
    case SENDING = 'sending';
    case SENT = 'sent';
    case FAILED = 'failed';
}
