<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum as Status;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;

trait ReviewsApplication
{
    /**
     * Re-approving an approved application is allowed on purpose: the approval path is
     * idempotent and admins retry it when a downstream workflow rule was missing.
     */
    protected function assertNotDecided(Model $application, Status $decision): void
    {
        $status = Status::currentFor($application);

        if ($status === Status::REJECTED) {
            throw new ValidationException('This application was already rejected.');
        }

        if ($status === Status::APPROVED && $decision === Status::REJECTED) {
            throw new ValidationException('An approved application cannot be rejected.');
        }
    }

    protected function stampReview(Model $application, Status $status, ?Users $reviewedBy): void
    {
        Field::STATUS->writeTo($application, $status->value);

        if ($reviewedBy === null) {
            return;
        }

        Field::REVIEWED_BY->writeTo($application, (string) $reviewedBy->getId());
        Field::REVIEWED_AT->writeTo($application, now()->toIso8601String());
    }
}
