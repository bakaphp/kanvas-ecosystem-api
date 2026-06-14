<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Invoices\Services\InvoiceStateMachine;

/**
 * Recomputes invoice.paid_native / balance_due_native from active allocations and flips status to PAID when
 * the balance hits zero. Called from Souk.Payments::markAsPaid() via Invoice::markAsPaid() and from
 * AllocatePaymentAction after every new allocation.
 *
 * Pure state-recompute — does NOT post a JE itself. The JE for "DR Cash / CR AR" is posted by the
 * AllocatePaymentAction (or its equivalent) when the allocation row is created. This Action's job is to
 * keep the invoice's denormalized totals in sync with the truth held in invoice_payment_allocations.
 *
 * Idempotent: re-running on an already-paid invoice is a no-op.
 *
 * @see plan §11 Flow 1 — payment cycle worked example
 */
class MarkInvoicePaidAction
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly ?UserInterface $user = null,
        protected readonly InvoiceStateMachine $stateMachine = new InvoiceStateMachine(),
    ) {
    }

    public function execute(): Invoice
    {
        return DB::connection('accounting')->transaction(function () {
            $invoice = $this->invoice;

            [$paidNative, $paidBase] = $this->sumActiveAllocations($invoice);

            $invoice->paid_native = $paidNative;
            $invoice->paid_base = $paidBase;
            $invoice->balance_due_native = max(0.0, (float) $invoice->total_native - $paidNative);
            $invoice->balance_due_base = max(0.0, (float) $invoice->total_base - $paidBase);

            // Transition to PAID only if the balance is zero AND we're in a non-terminal state that allows it.
            if ($invoice->balance_due_native <= 0 && ! $invoice->document_status->isTerminal()) {
                if ($this->stateMachine->canTransition($invoice->document_status, InvoiceDocumentStatusEnum::PAID)) {
                    $invoice->document_status = InvoiceDocumentStatusEnum::PAID;
                    $invoice->collection_state = null;       // cleared on payment per plan §7.1
                }
            }

            $invoice->save();

            return $invoice->refresh();
        });
    }

    /**
     * @return array{0: float, 1: float} [paidNative, paidBase]
     */
    private function sumActiveAllocations(Invoice $invoice): array
    {
        $rows = InvoicePaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', AllocationStatusEnum::ACTIVE->value)
            ->get(['amount_native', 'amount_base']);

        return [
            (float) $rows->sum('amount_native'),
            (float) $rows->sum('amount_base'),
        ];
    }
}
