<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Enums;

enum FollowUpTypeEnum: string
{
    case LEAD_FOLLOW_UP = 'lead_follow_up';
    case SOLD_LEAD_FOLLOW_UP = 'sold_lead_follow_up';
}
