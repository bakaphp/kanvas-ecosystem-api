<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Users\Models\Users;

/**
 * Links a Kanvas User as an approver for this Organization's AP/AR items.
 *
 * Idempotent, and it revives rather than duplicates: the (organizations_id, users_id) pair is unique,
 * so re-adding someone previously removed has to flip is_deleted back instead of inserting a second row.
 */
class AddApproverToOrganizationAction
{
    public function __construct(
        protected readonly Organization $organization,
        protected readonly Users $user,
    ) {
    }

    public function execute(): OrganizationApprover
    {
        $approver = OrganizationApprover::query()
            ->where('organizations_id', $this->organization->getId())
            ->where('users_id', $this->user->getId())
            ->first();

        if ($approver === null) {
            return OrganizationApprover::create([
                'organizations_id' => $this->organization->getId(),
                'users_id' => $this->user->getId(),
            ]);
        }

        if ($approver->is_deleted) {
            $approver->is_deleted = false;
            $approver->saveOrFail();
        }

        return $approver;
    }
}
