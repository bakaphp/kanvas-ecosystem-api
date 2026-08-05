<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Enums;

enum CampaignRecipientStatusEnum: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
