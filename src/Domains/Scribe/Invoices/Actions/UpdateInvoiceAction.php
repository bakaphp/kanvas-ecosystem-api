<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\Actions\Concerns\PersistsInvoiceLines;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Kanvas\Scribe\Invoices\Models\InvoiceTaxLine;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Updates a DRAFT invoice (header + lines + tax lines + billable reference).
 *
 * Hard-gated to document_status=DRAFT — once an invoice has been issued, header mutations are not allowed
 * (issued invoices have posted JEs and frozen billable snapshots). For post-issue changes the legal paths
 * are:
 *   - AmendInvoiceAction (post-issue mutation w/ diff event)
 *   - IssueCreditNoteAction (negative-shaped credit note bound to the parent invoice)
 *   - VoidInvoiceAction + re-create (clean slate)
 *
 * Lines + tax lines are replaced wholesale (delete + re-insert). Draft invoices don't have JE history
 * tying line ids to anything, so churning ids is harmless.
 */
class UpdateInvoiceAction
{
    use PersistsInvoiceLines;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly InvoiceData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Invoice
    {
        // An issued e-CF cannot be edited by law; the change goes out as credit/debit notes and the invoice
        // itself comes back exactly as it was, with the notes under credit_notes / debit_notes.
        if (CorrectIssuedInvoiceAction::isLiveEcf($this->invoice)) {
            new CorrectIssuedInvoiceAction(
                invoice: $this->invoice,
                data: $this->data,
                user: $this->user,
            )->execute();

            return $this->invoice->refresh();
        }

        if ($this->invoice->document_status !== InvoiceDocumentStatusEnum::DRAFT) {
            throw new InvalidInvoiceTransitionException(
                "Invoice {$this->invoice->id} cannot be updated — status is "
                . "'{$this->invoice->document_status->value}'. Only draft invoices are editable. "
                . 'Use AmendInvoiceAction / IssueCreditNoteAction / VoidInvoiceAction for post-issue changes.'
            );
        }

        $invoice = DB::connection('accounting')->transaction(function (): Invoice {
            $invoice = $this->invoice;
            [$totals, $baseTotals] = $this->data->totals();

            $invoice->document_type = $this->data->document_type;
            $invoice->invoice_number = $this->data->invoice_number;
            $invoice->tax_calculation_mode = $this->data->tax_calculation_mode;
            $invoice->net_terms_days = $this->data->net_terms_days ?? 0;
            $invoice->expected_payment_date = $this->data->expected_payment_date;
            $invoice->issued_date = $this->data->issued_date;
            $invoice->due_date = $this->data->due_date;
            $invoice->currency = $this->data->currency;
            $invoice->fx_rate_to_base = $this->data->fx_rate_to_base;
            $invoice->fx_rate_at = $this->data->issued_date ?? Carbon::now();
            $invoice->subtotal_native = $totals['subtotal'];
            $invoice->tax_native = $totals['tax'];
            $invoice->discount_native = $totals['discount'];
            $invoice->total_native = $totals['total'];
            $invoice->balance_due_native = $totals['total'];
            $invoice->subtotal_base = $baseTotals['subtotal'];
            $invoice->tax_base = $baseTotals['tax'];
            $invoice->discount_base = $baseTotals['discount'];
            $invoice->total_base = $baseTotals['total'];
            $invoice->balance_due_base = $baseTotals['total'];
            $invoice->tax_metadata = $this->data->tax_metadata;
            $invoice->regional_compliance = $this->data->regional_compliance;
            $invoice->notes = $this->data->notes;
            $invoice->internal_notes = $this->data->internal_notes;
            $invoice->terms = $this->data->terms;
            $invoice->quote_id = $this->data->quote_id;
            $invoice->parent_invoice_id = $this->data->parent_invoice_id;
            $invoice->metadata = $this->data->metadata;

            if ($this->data->billable !== null) {
                $invoice->customer_organization_id = $this->data->billable->getBillableId();
            } else {
                $invoice->customer_organization_id = null;
            }

            $invoice->billable_display_name = $this->data->billable_display_name;
            $invoice->billable_legal_name = $this->data->billable_legal_name;
            $invoice->billable_tax_id = $this->data->billable_tax_id;
            $invoice->billable_email = $this->data->billable_email;
            $invoice->billing_address_snapshot = $this->data->billing_address_snapshot;
            $invoice->shipping_address_snapshot = $this->data->shipping_address_snapshot;

            $invoice->save();

            InvoiceLine::query()->where('invoice_id', $invoice->id)->delete();
            InvoiceTaxLine::query()->where('invoice_id', $invoice->id)->delete();

            $this->persistLines($invoice, $this->data);
            $this->persistTaxLines($invoice, $this->data);

            $invoice->load(['lines', 'taxLines']);

            return $invoice;
        });

        $invoice->fireWorkflowAfterCommit(
            WorkflowEnum::UPDATED->value,
            [
                'app' => $invoice->app,
                'entity' => 'invoice',
            ]
        );

        return $invoice;
    }
}
