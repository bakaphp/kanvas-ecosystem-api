<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Users\Models\Users;

class RemoveApproverFromOrganizationAction
{
    public function __construct(
        protected readonly Organization $organization,
        protected readonly Users $user,
    ) {
    }

    public function execute(): bool
    {
        /** @var OrganizationApprover|null $approver */
        $approver = OrganizationApprover::query()
            ->where('organizations_id', $this->organization->getId())
            ->where('users_id', $this->user->getId())
            ->notDeleted()
            ->first();

        if ($approver === null) {
            return false;
        }

        $approver->is_deleted = true;

        return $approver->saveOrFail();
    }
}
