<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Contracts\ReactsToApprovalExpiryInterface;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalWorkflowService;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Closes requests nobody answered in time, lets the entity walk itself back out of limbo
 * (ReactsToApprovalExpiryInterface — a bill returns to DRAFT), and tells the people involved.
 *
 * Expiry fires its own event rather than reusing `rejected`: an approval that ran out of clock is not
 * a decision, and a tenant usually wants to escalate it rather than treat the record as refused.
 */
class ExpireApprovalRequestsAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly ApprovalWorkflowService $workflow = new ApprovalWorkflowService(),
    ) {
    }

    /**
     * @return int how many requests were expired
     */
    public function execute(): int
    {
        $now = Carbon::now();

        $expired = 0;

        ApprovalRequest::query()
            ->where('apps_id', $this->app->getId())
            ->where('status', ApprovalStatusEnum::PENDING->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->chunkById(200, function ($requests) use (&$expired, $now): void {
                foreach ($requests as $request) {
                    $expired += $this->expire($request, $now) ? 1 : 0;
                }
            });

        return $expired;
    }

    private function expire(ApprovalRequest $request, Carbon $now): bool
    {
        // An approver deciding in the same instant as the sweep must win, not be overwritten by it.
        $claimed = $request->claimIfPending(ApprovalStatusEnum::EXPIRED, [
            'resolved_at' => $now,
            'reason' => 'No decision before the request expired.',
        ]);

        if (! $claimed) {
            return false;
        }

        $request->skipUndecidedApprovers();
        $request->refresh();

        new RunWithdrawalHandlerAction($request)->execute();

        $entity = $request->resolveEntity();
        $this->runEntityExpiryHook($request, $entity);
        new NotifyApprovalExpiredAction($request, $entity)->execute();

        $this->workflow->fire($request, WorkflowEnum::APPROVAL_EXPIRED);

        return true;
    }

    /**
     * A hook that throws must not resurrect the expiry, which is already claimed and recorded.
     */
    private function runEntityExpiryHook(ApprovalRequest $request, ?Model $entity): void
    {
        if (! $entity instanceof ReactsToApprovalExpiryInterface) {
            return;
        }

        try {
            $entity->approvalExpired($request);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
