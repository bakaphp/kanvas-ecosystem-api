<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalWorkflowService;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Closes requests nobody answered in time.
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

        $this->workflow->fire($request->refresh(), WorkflowEnum::APPROVAL_EXPIRED);

        return true;
    }
}
