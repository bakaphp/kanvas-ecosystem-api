<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Approvals\Traits\ReadsApprovalSourceFields;
use Kanvas\Scribe\Bills\Actions\ApproveBillAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * @deprecated Use ApproveAction.
 *
 * Only reachable for a tenant with no approval policy, since a migrated tenant never gets an
 * ApprovalQueueItem to resolve. Its match arms live on as registered handler classes named by a
 * policy row, so nothing new should be added here — a new approval type is a policy row plus a
 * handler, not another case.
 *
 * Resolves a pending ApprovalQueueItem by dispatching on action_type to the domain action that
 * knows how to carry it out.
 */
class ResolveApprovalAction
{
    use ReadsApprovalSourceFields;

    public function __construct(
        protected readonly ApprovalQueueItem $item,
        protected readonly UserInterface $approver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        if ($this->item->status !== ApprovalQueueStatusEnum::PENDING) {
            throw new ValidationException(
                "This approval request is already {$this->item->status->value}, not pending."
            );
        }

        $result = match ($this->item->action_type) {
            'approve_bill' => $this->resolveBill(),
            'approve_invoice' => $this->resolveInvoice(),
            default => throw new ValidationException(
                "No approval handler registered for action_type \"{$this->item->action_type}\"."
            ),
        };

        $this->item->status = ApprovalQueueStatusEnum::APPROVED;
        $this->item->approved_by_users_id = $this->approver->getId();
        $this->item->approved_at = Carbon::now();
        $this->item->save();

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBill(): array
    {
        /** @var Bill|null $bill */
        $bill = Bill::query()
            ->where('id', $this->item->target_id)
            ->where('apps_id', $this->item->apps_id)
            ->where('companies_id', $this->item->companies_id)
            ->first();

        if ($bill === null) {
            throw new ValidationException("Bill {$this->item->target_id} no longer exists.");
        }

        /** @var Organization|null $vendor */
        $vendor = Organization::query()
            ->where('id', $bill->vendor_organization_id)
            ->where('apps_id', $this->item->apps_id)
            ->where('companies_id', $this->item->companies_id)
            ->first();

        if ($vendor === null) {
            throw new ValidationException("Vendor for bill {$bill->getId()} no longer exists.");
        }

        $bill = new ApproveBillAction($bill, $vendor, $this->approver)->execute();

        return [
            'target_type' => 'bill',
            'target_id' => $bill->getId(),
            'label' => $bill->bill_number,
            ...$this->sourceFields($bill),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveInvoice(): array
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()
            ->where('id', $this->item->target_id)
            ->where('apps_id', $this->item->apps_id)
            ->where('companies_id', $this->item->companies_id)
            ->first();

        if ($invoice === null) {
            throw new ValidationException("Invoice {$this->item->target_id} no longer exists.");
        }

        /** @var Organization|null $customer */
        $customer = Organization::query()
            ->where('id', $invoice->customer_organization_id)
            ->where('apps_id', $this->item->apps_id)
            ->where('companies_id', $this->item->companies_id)
            ->first();

        if ($customer === null) {
            throw new ValidationException("Customer for invoice {$invoice->getId()} no longer exists.");
        }

        $invoice = new IssueInvoiceAction($invoice, $customer, $this->approver)->execute();

        return [
            'target_type' => 'invoice',
            'target_id' => $invoice->getId(),
            'label' => $invoice->invoice_number,
            ...$this->sourceFields($invoice),
        ];
    }
}
