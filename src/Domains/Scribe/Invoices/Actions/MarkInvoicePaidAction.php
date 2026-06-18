<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Invoices\Services\InvoiceStateMachineService;
use Kanvas\Scribe\Ledger\Services\PayableMarkAsPaidExecutorService;

/**
 * Recomputes invoice paid/balance from active allocations and flips status to PAID when the balance
 * hits zero. Workflow is shared with the AP side — see `PayableMarkAsPaidExecutorService`.
 *
 * Called from Souk.Payments::markAsPaid() via Invoice::markAsPaid() and from AllocatePaymentAction
 * after every new allocation. Idempotent on already-paid invoices.
 *
 * @see plan §11 Flow 1 — payment cycle worked example
 */
class MarkInvoicePaidAction
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly ?UserInterface $user = null,
        protected readonly InvoiceStateMachineService $stateMachine = new InvoiceStateMachineService(),
        protected readonly PayableMarkAsPaidExecutorService $executor = new PayableMarkAsPaidExecutorService(),
    ) {
    }

    public function execute(): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = $this->executor->execute(
            entity: $this->invoice,
            paidStatusEnum: InvoiceDocumentStatusEnum::PAID,
            canTransitionToPaid: fn ($current) => $this->stateMachine->canTransition(
                $current,
                InvoiceDocumentStatusEnum::PAID,
            ),
            allocationModelClass: InvoicePaymentAllocation::class,
            allocationFkColumn: 'invoice_id',
            ledgerEventName: 'scribe.invoice.paid',
            extraEventPayload: ['invoice_number' => $this->invoice->invoice_number],
        );

        return $invoice;
    }
}
