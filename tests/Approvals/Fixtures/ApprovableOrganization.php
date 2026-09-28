<?php

declare(strict_types=1);

namespace Tests\Approvals\Fixtures;

use Kanvas\Approvals\Traits\HasApprovals;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * A cheap approvable entity for exercising HasApprovals without adding the trait to a production
 * model before its policy exists. Deliberately a real Eloquent model on a real table (organizations,
 * on the `crm` connection) rather than a mock: the point is to prove the system-module resolution and
 * the cross-connection relation to approval_requests on `ecosystem` actually work.
 */
class ApprovableOrganization extends Organization
{
    use HasApprovals;

    protected $table = 'organizations';
}
