<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

use Illuminate\Database\Eloquent\Model;

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

    /**
     * Null means the lead carries no status field at all, which is how a lead that is not an
     * application — and a webhook lead the triage activity has not reached yet — reads.
     */
    public static function currentFor(Model $application): ?self
    {
        return self::tryFrom((string) CorporateApplicationFieldEnum::STATUS->readFrom($application));
    }
}
