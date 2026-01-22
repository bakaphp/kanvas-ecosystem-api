<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Enums;

enum FollowUpTypeEnum: int
{
    case LEAD_FOLLOW_UP = 1;
    case SOLD_LEAD_FOLLOW_UP = 2;
    case NO_FOLLOW_UP = 3;
}
