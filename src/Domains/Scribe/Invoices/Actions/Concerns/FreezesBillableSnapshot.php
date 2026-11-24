<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions\Concerns;

use Baka\Contracts\BillableInterface;
use Kanvas\Scribe\Invoices\Models\Invoice;

trait FreezesBillableSnapshot
{
    /**
     * A snapshot value the draft already carries (an override typed on the invoice) wins over the live billable.
     */
    protected function freezeBillableSnapshot(Invoice $invoice, BillableInterface $billable): void
    {
        $invoice->customer_organization_id = $billable->getBillableId();
        $invoice->billable_display_name = $invoice->billable_display_name ?? $billable->getBillableDisplayName();
        $invoice->billable_legal_name = $invoice->billable_legal_name ?? $billable->getBillableLegalName();
        $invoice->billable_tax_id = $invoice->billable_tax_id ?? $billable->getBillableTaxId();
        $invoice->billable_email = $invoice->billable_email ?? $billable->getBillingEmail();
        $invoice->billing_address_snapshot = $invoice->billing_address_snapshot ?? $billable->getBillingAddressArray();
    }
}
