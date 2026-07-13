<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mercury\Services\MercuryInvoiceService;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Throwable;

/**
 * Without this, a voided invoice leaves its Mercury pay page live — the customer can still pay, and the cash
 * lands with no open receivable to match.
 *
 * Cancel, never delete, and KEEP `MERCURY_INVOICE_ID`. Mercury has no delete (405); it cancels and retains,
 * so clearing our reference would orphan a record that still exists on their side.
 *
 * Never throws: the void already posted its reversal and must not be undone because an API was unreachable.
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
