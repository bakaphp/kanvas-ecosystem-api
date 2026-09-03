<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalWorkflowService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Records a rejection. Whether one "no" kills the request is the policy's `reject_policy`:
 *
 *   any  (default) — any rejection ends it. The safe default for money.
 *   step           — a single no is just that person's no; the request only dies when the step can
 *                    no longer reach its quorum (2-of-3 survives one rejection, not two).
 */
class RejectAction
{
    public function __construct(
        protected readonly ApprovalRequest $request,
        protected readonly UserInterface $approver,
        protected readonly ?string $reason = null,
        protected readonly ApprovalWorkflowService $workflow = new ApprovalWorkflowService(),
    ) {
    }

    public function execute(): ApprovalResult
    {
        if ($this->request->status !== ApprovalStatusEnum::PENDING) {
            throw new ValidationException(
                "This approval is already {$this->request->status->value}, not pending."
            );
        }

        $row = $this->request->requireApproverRow($this->approver);
        $row->decision = ApprovalDecisionEnum::REJECTED;
        $row->decided_at = Carbon::now();
        $row->comment = $this->reason;
        $row->saveOrFail();

        if ($this->request->policy?->reject_policy === 'step' && $this->quorumStillReachable()) {
            return ApprovalResult::stillPending(
                $this->request,
                $this->request->approvalsAtCurrentStep(),
                $this->request->requiredApprovalsAtCurrentStep()
            );
        }

        return $this->rejectRequest();
    }

    private function quorumStillReachable(): bool
    {
        $stillPossible = $this->request->approvalsAtCurrentStep() + $this->request->approvers()
            ->where('step', $this->request->current_step)
            ->where('decision', ApprovalDecisionEnum::PENDING)
            ->count();

        return $stillPossible >= $this->request->requiredApprovalsAtCurrentStep();
    }

    private function rejectRequest(): ApprovalResult
    {
        $claimed = $this->request->claimIfPending(ApprovalStatusEnum::REJECTED, [
            'resolved_by_users_id' => $this->approver->getId(),
            'resolved_at' => Carbon::now(),
            'reason' => $this->reason,
        ]);

        if (! $claimed) {
            return ApprovalResult::alreadyResolved($this->request->refresh());
        }

        $this->request->skipUndecidedApprovers();
        $this->request->refresh();

        $this->workflow->fire($this->request, WorkflowEnum::REJECTED, [
            'approver' => $this->approver,
            'reason' => $this->reason,
        ]);

        return ApprovalResult::rejected($this->request);
    }
}
