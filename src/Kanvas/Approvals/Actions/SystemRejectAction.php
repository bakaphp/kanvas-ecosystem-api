<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Kanvas\Approvals\Contracts\ApprovalRejectionHandlerInterface;
use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalWorkflowService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Rejects without a human — a newer request superseding a stale one, a backfill, an admin force-close.
 *
 * Deliberately a SEPARATE class rather than a `system: true` flag on RejectAction: a flag would make
 * every future call site a place where authorization *might* have been skipped, with no way to find
 * them — same reasoning as SystemApproveAction. `grep -r SystemRejectAction src/` is a complete list
 * of every rejection nobody made.
 *
 * Not built on top of RejectAction's own logic: that class has a `reject_policy === 'step'` branch
 * deciding whether a single "no" needs to wait for quorum before closing the request. That question
 * does not apply here — a superseded request closes unconditionally, regardless of the policy's
 * reject_policy, so reusing that branch would be wrong, not just redundant.
 */
class SystemRejectAction
{
    public function __construct(
        protected readonly ApprovalRequest $request,
        protected readonly string $reason,
        protected readonly ApprovalWorkflowService $workflow = new ApprovalWorkflowService(),
    ) {
    }

    public function execute(): ApprovalResult
    {
        if (trim($this->reason) === '') {
            throw new ValidationException('A system rejection must record why no human rejected it.');
        }

        $this->request->assertPending();

        $claimed = $this->request->claimIfPending(ApprovalStatusEnum::REJECTED, [
            'resolved_at' => now(),
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
            'approver' => null,
            'reason' => $this->reason,
            'result' => $handlerResult,
            'system_rejected' => true,
        ]);

        return ApprovalResult::rejected($this->request, $handlerResult);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runRejectionHandler(): ?array
    {
        $handler = $this->request->policy?->handlerInstance();

        if (! $handler instanceof ApprovalRejectionHandlerInterface) {
            return null;
        }

        try {
            return $handler->reject($this->request, null, $this->reason);
        } catch (Throwable $e) {
            return ['handler_error' => $e->getMessage()];
        }
    }
}
