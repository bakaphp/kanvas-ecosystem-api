<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

enum CorporateApplicationStatusEnum: string
{
    case PENDING = 'pending';
    case NEEDS_REVIEW = 'needs_review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public static function openValues(): array
    {
        return [self::PENDING->value, self::NEEDS_REVIEW->value];
    }
}
