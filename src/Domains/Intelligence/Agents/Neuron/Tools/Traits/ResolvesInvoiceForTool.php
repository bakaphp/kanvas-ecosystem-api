<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Resolves an AR invoice/credit memo for an accounting tool. Requires HasKanvasContext ($app, $company).
 * Returns the Invoice, or an LLM-facing error array the caller
 * merges into its own response shape (e.g. `['note_added' => false, ...$invoice]`).
 */
trait ResolvesInvoiceForTool
{
    /**
     * @return Invoice|array{reason: string, message: string}
     */
    protected function resolveInvoice(int $invoiceId): Invoice|array
    {
        $invoice = Invoice::query()
            ->where('id', $invoiceId)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($invoice === null) {
            return [
                'reason' => 'invoice_not_found',
                'message' => "No invoice with id {$invoiceId} for this app/company.",
            ];
        }

        return $invoice;
    }
}
