<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\Actions\Concerns\PersistsInvoiceLines;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Creates a DRAFT invoice (or credit_note draft). No JE posts — drafts haven't hit the books yet.
 *
 * Side effects:
 *   - persists invoice header + lines + tax lines in a single accounting-DB transaction
 *   - computes header totals from lines (subtotal/tax/discount/total + base equivalents)
 *   - DOES NOT hydrate billable snapshot — that happens at Issue time
 *   - DOES NOT post a JE — that happens at Issue time
 *
 * @see IssueInvoiceAction — what flips draft → issued + posts the JE + freezes snapshot
 */
class CreateInvoiceAction
{
    use PersistsInvoiceLines;

    public function __construct(
        public readonly InvoiceData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Invoice
    {
        $invoice = DB::connection('accounting')->transaction(function (): Invoice {
            [$totals, $baseTotals] = $this->data->totals();

            $invoice = new Invoice();
            $invoice->apps_id = $this->data->app->getId();
            $invoice->companies_id = $this->data->company->getId();
            $invoice->document_type = $this->data->document_type;
            $invoice->invoice_number = $this->data->invoice_number;
            $invoice->document_status = InvoiceDocumentStatusEnum::DRAFT;
            $invoice->collection_state = null;
            $invoice->tax_calculation_mode = $this->data->tax_calculation_mode;
            $invoice->delivery_status = 'not_applicable';
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
            $invoice->paid_native = 0.0;
            $invoice->balance_due_native = $totals['total'];
            $invoice->subtotal_base = $baseTotals['subtotal'];
            $invoice->tax_base = $baseTotals['tax'];
            $invoice->discount_base = $baseTotals['discount'];
            $invoice->total_base = $baseTotals['total'];
            $invoice->paid_base = 0.0;
            $invoice->balance_due_base = $baseTotals['total'];
            $invoice->tax_metadata = $this->data->tax_metadata;
            $invoice->regional_compliance = $this->data->regional_compliance;
            $invoice->notes = $this->data->notes;
            $invoice->internal_notes = $this->data->internal_notes;
            $invoice->terms = $this->data->terms;
            $invoice->quote_id = $this->data->quote_id;
            $invoice->order_id = $this->data->order?->getId();
            $invoice->parent_invoice_id = $this->data->parent_invoice_id;
            $invoice->source = $this->data->source;
            $invoice->external_id = $this->data->external_id;
            $invoice->external_url = $this->data->external_url;
            $invoice->origin = $this->data->origin;

            $invoice->billable_display_name = $this->data->billable_display_name;
            $invoice->billable_legal_name = $this->data->billable_legal_name;
            $invoice->billable_tax_id = $this->data->billable_tax_id;
            $invoice->billable_email = $this->data->billable_email;
            $invoice->billing_address_snapshot = $this->data->billing_address_snapshot;
            $invoice->shipping_address_snapshot = $this->data->shipping_address_snapshot;
            $invoice->metadata = $this->data->metadata;
            $invoice->users_id = $this->user?->getId();

            // Billable reference (the polymorphic id+type; snapshot fields stay null until Issue)
            if ($this->data->billable !== null) {
                $invoice->customer_organization_id = $this->data->billable->getBillableId();
            }

            $invoice->save();

            $this->persistLines($invoice, $this->data);
            $this->persistTaxLines($invoice, $this->data);

            $invoice->load(['lines', 'taxLines']);

            return $invoice;
        });

        $invoice->fireWorkflowAfterCommit(
            WorkflowEnum::CREATED->value,
            [
                'app' => $invoice->app,
                'entity' => 'invoice',
            ]
        );

        return $invoice;
    }
}
