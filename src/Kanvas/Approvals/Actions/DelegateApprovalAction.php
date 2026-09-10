<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;

/**
 * Hands one approver's turn to someone else — the out-of-office case.
 *
 * The original row is kept as DELEGATED rather than reassigned: the audit has to show the request
 * went to the person it was supposed to go to, and who they passed it to.
 */
class DelegateApprovalAction
{
    public function __construct(
        protected readonly ApprovalRequest $request,
        protected readonly UserInterface $from,
        protected readonly UserInterface $to,
        protected readonly ?string $comment = null,
    ) {
    }

    public function execute(): ApprovalResult
    {
        $this->request->assertPending();

        if ($this->from->getId() === $this->to->getId()) {
            throw new ValidationException('An approver cannot delegate to themselves.');
        }

        $row = $this->request->requireApproverRow($this->from);

        $row->decision = ApprovalDecisionEnum::DELEGATED;
        $row->delegated_to_users_id = $this->to->getId();
        $row->decided_at = Carbon::now();
        $row->comment = $this->comment;
        $row->saveOrFail();

        $this->request->grantTurnAtCurrentStep($this->to);

        return ApprovalResult::delegated($this->request->refresh());
    }
}
