<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Services\CompanyAuthorityService;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

/**
 * Creates or edits a policy on behalf of a person. A policy decides who must sign before money moves,
 * so only the company's owner or an admin may change it, and the write is audited with them as actor
 * (ApprovalPolicyObserver emits `approvals.policy_changed`).
 */
class SaveApprovalPolicyAction
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        protected readonly ApprovalPolicy $policy,
        protected readonly array $attributes,
        protected readonly Users $actor,
    ) {
    }

    public function execute(): ApprovalPolicy
    {
        $this->policy->fill($this->attributes);

        /** @var Companies $company */
        $company = $this->policy->company;
        CompanyAuthorityService::assertOwnerOrAdmin($company, $this->actor, 'change an approval policy');

        $this->policy->changedBy = $this->actor;

        try {
            $this->policy->saveOrFail();
        } finally {
            $this->policy->changedBy = null;
        }

        return $this->policy;
    }
}
