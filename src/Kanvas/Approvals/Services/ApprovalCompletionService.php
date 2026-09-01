<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Services;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Closes a request as approved: claims it, runs the policy's synchronous handler, fires the workflow.
 * Shared by the human path (ApproveAction) and the ruleful one (SystemApproveAction) so the claim and
 * the handler can only ever be reached one way.
 */
class ApprovalCompletionService
{
    public function __construct(
        protected readonly ApprovalWorkflowService $workflow = new ApprovalWorkflowService(),
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function complete(
        ApprovalRequest $request,
        ?UserInterface $approver = null,
        ?string $reason = null,
        array $metadata = []
    ): ApprovalResult {
        if (! $this->claim($request, $approver, $reason, $metadata)) {
            return ApprovalResult::alreadyResolved($request->refresh());
        }

        $request->refresh();

        $handlerResult = $this->runHandler($request, $approver);

        $this->workflow->fire($request, WorkflowEnum::APPROVED, [
            'approver' => $approver,
            'result' => $handlerResult,
        ]);

        return ApprovalResult::approved($request, $handlerResult);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function claim(
        ApprovalRequest $request,
        ?UserInterface $approver,
        ?string $reason,
        array $metadata
    ): bool {
        return $request->claimIfPending(ApprovalStatusEnum::APPROVED, [
            'resolved_by_users_id' => $approver?->getId(),
            'resolved_at' => Carbon::now(),
            'reason' => $reason,
            'metadata' => json_encode([...($request->metadata ?? []), ...$metadata]),
        ]);
    }

    /**
     * A handler that throws must not undo an approval that is already recorded — the decision was
     * made. The failure is reported back in the result so the caller can say "approved, but the push
     * failed" instead of claiming a clean success, which mirrors how the AP flow already treats an
     * Acumatica write error.
     *
     * @return array<string, mixed>|null
     */
    private function runHandler(ApprovalRequest $request, ?UserInterface $approver): ?array
    {
        $handler = $request->policy?->handlerInstance();

        if ($handler === null) {
            return null;
        }

        try {
            return $handler->handle($request, $approver);
        } catch (Throwable $e) {
            return ['handler_error' => $e->getMessage()];
        }
    }
}
