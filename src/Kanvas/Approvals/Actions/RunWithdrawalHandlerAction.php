<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Kanvas\Approvals\Contracts\ApprovalWithdrawalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Throwable;

/**
 * Tells the policy's handler that its request closed without a decision. Shared by cancellation and
 * expiry so the two cannot drift. A handler that throws must not resurrect a withdrawal that is
 * already recorded: the failure is reported beside it, never allowed to undo it.
 */
class RunWithdrawalHandlerAction
{
    public function __construct(
        protected readonly ApprovalRequest $request,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the policy's handler has nothing to do on a withdrawal
     */
    public function execute(): ?array
    {
        $handler = $this->request->policy?->handlerInstance();

        if (! $handler instanceof ApprovalWithdrawalHandlerInterface) {
            return null;
        }

        try {
            return $handler->withdrawn($this->request, $this->request->reason);
        } catch (Throwable $e) {
            report($e);

            return ['handler_error' => $e->getMessage()];
        }
    }
}
