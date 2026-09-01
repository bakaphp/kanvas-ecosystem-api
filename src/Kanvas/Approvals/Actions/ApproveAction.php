<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalCompletionService;
use Kanvas\Approvals\Services\ApprovalWorkflowService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Records one person's approval, then advances the chain or closes it.
 *
 * This is the single entry point for approving — the GraphQL mutation, an LLM tool, a command and
 * tinker all come through here, so the authorization check cannot be bypassed by a caller that
 * forgot a trait.
 */
class ApproveAction
{
    public function __construct(
        protected readonly ApprovalRequest $request,
        protected readonly UserInterface $approver,
        protected readonly ?string $comment = null,
        protected readonly ApprovalCompletionService $completion = new ApprovalCompletionService(),
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
        $row->decision = ApprovalDecisionEnum::APPROVED;
        $row->decided_at = Carbon::now();
        $row->comment = $this->comment;
        $row->saveOrFail();

        $needed = $this->request->requiredApprovalsAtCurrentStep();
        $have = $this->request->approvalsAtCurrentStep();

        if ($have < $needed) {
            return ApprovalResult::stillPending($this->request, $have, $needed);
        }

        $nextStep = $this->request->nextLiveStep();

        return $nextStep !== null
            ? $this->advanceTo($nextStep)
            : $this->completion->complete($this->request, $this->approver, $this->comment);
    }

    private function advanceTo(int $step): ApprovalResult
    {
        $this->request->current_step = $step;
        $this->request->saveOrFail();

        $this->request->approvers()
            ->where('step', $step)
            ->where('decision', ApprovalDecisionEnum::WAITING)
            ->update(['decision' => ApprovalDecisionEnum::PENDING->value]);

        new NotifyApproversAction($this->request)->execute();

        $this->workflow->fire(
            $this->request,
            WorkflowEnum::APPROVAL_STEP_COMPLETED,
            [
                'approver' => $this->approver,
                'step' => $step,
            ]
        );

        return ApprovalResult::advanced($this->request, $step);
    }
}
