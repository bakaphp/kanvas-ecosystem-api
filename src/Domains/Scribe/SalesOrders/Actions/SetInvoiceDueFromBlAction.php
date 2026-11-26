<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoice as AmendInvoiceData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Moves an issued invoice's due date to the bill of lading date plus the payment term. It is an amendment, so the
 * change is recorded on the ledger with its reason; the invoice itself stays as issued.
 */
class SetInvoiceDueFromBlAction
{
    public function __construct(
        protected readonly Invoice $invoice,
        protected readonly Carbon $dueDate,
        protected readonly string $blNumber,
        protected readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Invoice
    {
        $paid = $this->invoice->document_status === InvoiceDocumentStatusEnum::PAID;

        // Paid before departure: nothing is left to collect, and a closed invoice's terms are not rewritten.
        if ($paid || $this->invoice->due_date?->isSameDay($this->dueDate)) {
            return $this->invoice;
        }

        return new AmendInvoiceAction(
            invoice: $this->invoice,
            data: new AmendInvoiceData(
                reason: "Due date set from the bill of lading {$this->blNumber}",
                due_date: $this->dueDate,
            ),
            user: $this->user,
        )->execute();
    }
}
