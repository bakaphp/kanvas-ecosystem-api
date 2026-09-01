<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalCompletionService;
use Kanvas\Exceptions\ValidationException;

/**
 * Approves without a human — timeout auto-approval, a backfill, an admin force-approve.
 *
 * Deliberately a SEPARATE class rather than a `system: true` flag on ApproveAction: a flag would make
 * every future call site a place where authorization *might* have been skipped, with no way to find
 * them. `grep -r SystemApproveAction src/` is a complete list of every approval granted without a
 * person, which is the whole point.
 *
 * Reach for this only when it genuinely cannot be policy. "Invoices under $500 skip a human" belongs
 * in a step's `when` condition, where it needs no bypass code at all.
 */
class SystemApproveAction
{
    public function __construct(
        protected readonly ApprovalRequest $request,
        protected readonly string $reason,
        protected readonly ApprovalCompletionService $completion = new ApprovalCompletionService(),
    ) {
    }

    public function execute(): ApprovalResult
    {
        if (trim($this->reason) === '') {
            throw new ValidationException('A system approval must record why no human approved it.');
        }

        if ($this->request->status !== ApprovalStatusEnum::PENDING) {
            throw new ValidationException(
                "This approval is already {$this->request->status->value}, not pending."
            );
        }

        // AUTO_APPROVED, not APPROVED: a report has to be able to separate "a person signed this"
        // from "a rule closed it".
        $this->request->approvers()
            ->whereIn('decision', [ApprovalDecisionEnum::PENDING, ApprovalDecisionEnum::WAITING])
            ->update([
                'decision' => ApprovalDecisionEnum::AUTO_APPROVED->value,
                'decided_at' => now(),
            ]);

        return $this->completion->complete(
            request: $this->request,
            reason: $this->reason,
            metadata: ['system_approved' => true, 'system_approval_reason' => $this->reason],
        );
    }
}
