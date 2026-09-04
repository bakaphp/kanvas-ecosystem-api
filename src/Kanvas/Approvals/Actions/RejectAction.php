<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Contracts\ApprovalRejectionHandlerInterface;
use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalWorkflowService;
use Kanvas\Approvals\Services\ApproverSelfAssignService;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

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
        protected readonly ApproverSelfAssignService $selfAssign = new ApproverSelfAssignService(),
    ) {
    }

    public function execute(): ApprovalResult
    {
        $this->request->assertPending();

        $this->selfAssign->ensureCanDecide($this->request, $this->approver);

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

        $handlerResult = $this->runRejectionHandler();

        if ($handlerResult !== null) {
            $this->request->metadata = [...($this->request->metadata ?? []), 'handler_result' => $handlerResult];
            $this->request->saveOrFail();
        }

        $this->workflow->fire($this->request, WorkflowEnum::REJECTED, [
            'approver' => $this->approver,
            'reason' => $this->reason,
            'result' => $handlerResult,
        ]);

        return ApprovalResult::rejected($this->request, $handlerResult);
    }

    /**
     * Most approvals have nothing to undo, so a policy whose handler does not implement the rejection
     * contract is the normal case and returns null. A handler that throws must not resurrect a
     * rejection that is already recorded — the "no" was said; the failure is reported alongside it.
     *
     * @return array<string, mixed>|null
     */
    private function runRejectionHandler(): ?array
    {
        $handler = $this->request->policy?->handlerInstance();

        if (! $handler instanceof ApprovalRejectionHandlerInterface) {
            return null;
        }

        try {
            return $handler->reject($this->request, $this->approver, $this->reason);
        } catch (Throwable $e) {
            return ['handler_error' => $e->getMessage()];
        }
    }
}
