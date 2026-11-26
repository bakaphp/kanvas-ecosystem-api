<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoice as AmendInvoiceData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Moves an invoice's due date to the bill of lading date plus the payment term. A draft takes it directly; an issued
 * or sent invoice is amended, so the change is recorded on the ledger with its reason.
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
        $status = $this->invoice->document_status;

        if (in_array($status, [InvoiceDocumentStatusEnum::PAID, InvoiceDocumentStatusEnum::VOIDED], true)) {
            throw new ValidationException("Invoice {$this->invoice->invoice_number} is {$status->value}: its due date is closed.");
        }

        if ($this->invoice->due_date?->isSameDay($this->dueDate)) {
            return $this->invoice;
        }

        if ($status === InvoiceDocumentStatusEnum::DRAFT) {
            $this->invoice->due_date = $this->dueDate;
            $this->invoice->saveOrFail();

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
