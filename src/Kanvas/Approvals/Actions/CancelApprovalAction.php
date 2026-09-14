<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalWorkflowService;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Withdraws an open request — the entity was deleted, voided, or superseded.
 *
 * Distinct from rejection: nobody decided anything, so this fires no approved/rejected workflow and
 * leaves no resolving user. Cancelling an already-closed request is a no-op, not an error, because
 * the callers are cleanup paths that should never be the reason a delete fails.
 */
class CancelApprovalAction
{
    public function __construct(
        protected readonly ApprovalRequest $request,
        protected readonly ?string $reason = null,
    ) {
    }

    public function execute(): ApprovalResult
    {
        $claimed = $this->request->claimIfPending(ApprovalStatusEnum::CANCELLED, [
            'resolved_at' => Carbon::now(),
            'reason' => $this->reason,
        ]);

        if (! $claimed) {
            return ApprovalResult::alreadyResolved($this->request->refresh());
        }

        $this->request->skipUndecidedApprovers();
        $this->request->refresh();

        // No workflow — nobody decided anything — but the audit still has to show it was withdrawn.
        new ApprovalWorkflowService()->record($this->request, WorkflowEnum::APPROVAL_CANCELLED);

        return ApprovalResult::cancelled($this->request);
    }
}
