<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Enums;

enum PeopleClassificationSourceEnum: string
{
    case AI = 'ai';
    case MANUAL = 'manual';
    case PENDING_REVIEW = 'pending_review';
}
