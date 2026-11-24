<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Services\CompanyAuthorityService;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

/**
 * Deleting a policy switches its gate off for the whole company, so it carries the same owner/admin
 * restriction and audit as an edit.
 */
class DeleteApprovalPolicyAction
{
    public function __construct(
        protected readonly ApprovalPolicy $policy,
        protected readonly Users $actor,
    ) {
    }

    public function execute(): bool
    {
        /** @var Companies $company */
        $company = $this->policy->company;
        CompanyAuthorityService::assertOwnerOrAdmin($company, $this->actor, 'delete an approval policy');

        $this->policy->changedBy = $this->actor;

        try {
            return $this->policy->softDelete();
        } finally {
            $this->policy->changedBy = null;
        }
    }
}
