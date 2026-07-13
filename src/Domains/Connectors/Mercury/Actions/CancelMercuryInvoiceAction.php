<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mercury\Services\MercuryInvoiceService;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Throwable;

/**
 * Cancels a voided invoice's Mercury copy.
 *
 * Without this, voiding an invoice in Scribe leaves the Mercury pay page live and the customer can still pay
 * it — money would land in the bank feed with no open receivable to match, and sit in Suspense while the
 * books say nothing is owed.
 *
 * **Cancel, never delete — and KEEP the reference.** Mercury has no delete for an invoice (`DELETE` answers
 * 405); it cancels and retains the record, which is the right behaviour for a document a customer has seen.
 * We mirror that: `MERCURY_INVOICE_ID` is deliberately left on the invoice after cancellation, so a voided
 * Scribe invoice still points at the cancelled Mercury one and the trail survives on both sides. Clearing it
 * would orphan the Mercury record — still there, no longer traceable to anything.
 *
 * Deliberately never throws. A void is an accounting act that has already happened and posted its reversal;
 * it must not be undone because a third-party API was unreachable. A failure is reported and leaves the
 * Mercury invoice live, which the nightly pull surfaces as a status mismatch.
 */
class CancelMercuryInvoiceAction
{
    public function __construct(
        public readonly Invoice $invoice,
        protected readonly ?MercuryInvoiceService $invoiceService = null,
    ) {
    }

    public function execute(): bool
    {
        $mercuryInvoiceId = (string) $this->invoice->get(CustomFieldEnum::INVOICE_ID->value);

        if ($mercuryInvoiceId === '' || $this->alreadyCancelled()) {
            return false;
        }

        try {
            $service = $this->invoiceService ?? new MercuryInvoiceService(
                $this->invoice->app,
                $this->invoice->company,
            );

            $service->cancel($mercuryInvoiceId);

            $this->invoice->set(CustomFieldEnum::INVOICE_CANCELLED_AT->value, Carbon::now()->toIso8601String());

            $this->invoice->emitLedgerEvent('accounting.invoice.mercury_cancelled', payload: [
                'mercury_invoice_id' => $mercuryInvoiceId,
                'invoice_number' => $this->invoice->invoice_number,
            ]);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public function alreadyCancelled(): bool
    {
        return (string) $this->invoice->get(CustomFieldEnum::INVOICE_CANCELLED_AT->value) !== '';
    }
}
