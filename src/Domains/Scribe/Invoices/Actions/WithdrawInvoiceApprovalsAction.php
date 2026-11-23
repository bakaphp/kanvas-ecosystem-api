<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Approvals\Actions\CancelApprovalAction;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Closes whatever is waiting on a draft whose content is about to change or go away: the request an
 * approver holds describes the invoice as it was, and approving it would issue something they never saw.
 *
 * Both stores are closed. A tenant with no approval policy still waits on the legacy queue, and a row
 * left pending there issues the invoice when someone approves it.
 */
class WithdrawInvoiceApprovalsAction
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $reason,
    ) {
    }

    /**
     * @return int how many requests were waiting
     * @throws ValidationException when one is still pending afterwards, so the caller changes nothing
     */
    public function execute(): int
    {
        $requests = $this->invoice->pendingApprovals();
        $requests->each(
            fn (ApprovalRequest $request) => new CancelApprovalAction($request, $this->reason)->execute()
        );

        // The legacy queue has no "withdrawn": expired is its one status for a request nobody decided.
        $legacy = $this->invoice->pendingLegacyApprovals()->update([
            'status' => ApprovalQueueStatusEnum::EXPIRED->value,
            'reason' => $this->reason,
            'expires_at' => Carbon::now(),
        ]);

        if ($this->invoice->pendingApprovals()->isNotEmpty() || $this->invoice->pendingLegacyApprovals()->exists()) {
            throw new ValidationException(
                "An approval of invoice {$this->invoice->getId()} could not be withdrawn and is still pending."
            );
        }

        return $requests->count() + $legacy;
    }
}
