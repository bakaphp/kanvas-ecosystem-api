<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceTaxLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Kanvas\Scribe\Invoices\Models\InvoiceTaxLine;

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
    public function __construct(
        public readonly InvoiceData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Invoice
    {
        return DB::connection('accounting')->transaction(function (): Invoice {
            [$totals, $baseTotals] = $this->computeTotals();

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
            $invoice->fx_rate_at = $this->data->issued_date ?? \Illuminate\Support\Carbon::now();
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
            $invoice->parent_invoice_id = $this->data->parent_invoice_id;
            $invoice->source = $this->data->source;
            $invoice->external_id = $this->data->external_id;
            $invoice->external_url = $this->data->external_url;
            $invoice->origin = $this->data->origin;
            $invoice->metadata = $this->data->metadata;
            $invoice->users_id = $this->user?->getId();

            // Billable reference (the polymorphic id+type; snapshot fields stay null until Issue)
            if ($this->data->billable !== null) {
                $invoice->customer_organization_id = $this->data->billable->getBillableId();
            }

            $invoice->save();

            $sortOrder = 0;
            $fxRate = (float) $this->data->fx_rate_to_base;
            foreach ($this->data->lines as $lineData) {
                /** @var InvoiceLineData $lineData */
                $line = new InvoiceLine();
                $line->invoice_id = $invoice->id;
                $line->sort_order = $lineData->sort_order ?? $sortOrder++;
                $line->item_id = $lineData->item_id;
                $line->sku = $lineData->sku;
                $line->description = $lineData->description;
                $line->quantity = $lineData->quantity;
                $line->unit_price_native = $lineData->unit_price_native;
                $line->unit_price_base = $lineData->unit_price_native * $fxRate;
                $line->discount_amount_native = $lineData->discount_amount_native;
                $line->discount_amount_base = $lineData->discount_amount_native * $fxRate;
                $line->discount_rate = $lineData->discount_rate;
                $line->tax_rate = $lineData->tax_rate;
                $line->tax_amount_native = $lineData->tax_amount_native;
                $line->tax_amount_base = $lineData->tax_amount_native * $fxRate;
                $line->line_total_native = $lineData->lineTotalNative();
                $line->line_total_base = $lineData->lineTotalNative() * $fxRate;
                $line->tax_metadata = $lineData->tax_metadata;
                $line->class_id = $lineData->class_id;
                $line->department_id = $lineData->department_id;
                $line->metadata = $lineData->metadata;
                $line->save();
            }

            if ($this->data->taxLines !== null) {
                foreach ($this->data->taxLines as $taxLineData) {
                    /** @var InvoiceTaxLineData $taxLineData */
                    $taxLine = new InvoiceTaxLine();
                    $taxLine->invoice_id = $invoice->id;
                    $taxLine->tax_code_id = $taxLineData->tax_code_id;
                    $taxLine->name = $taxLineData->name;
                    $taxLine->tax_rate = $taxLineData->tax_rate;
                    $taxLine->jurisdiction = $taxLineData->jurisdiction;
                    $taxLine->tax_amount_native = $taxLineData->tax_amount_native;
                    $taxLine->tax_amount_base = $taxLineData->tax_amount_native * $fxRate;
                    $taxLine->metadata = $taxLineData->metadata;
                    $taxLine->save();
                }
            }

            $invoice->load(['lines', 'taxLines']);

            return $invoice;
        });
    }

    /**
     * @return array{0: array{subtotal: float, tax: float, discount: float, total: float}, 1: array{subtotal: float, tax: float, discount: float, total: float}}
     */
    private function computeTotals(): array
    {
        $fxRate = (float) $this->data->fx_rate_to_base;

        $subtotal = 0.0;
        $tax = 0.0;
        $discount = 0.0;

        foreach ($this->data->lines as $line) {
            /** @var InvoiceLineData $line */
            $subtotal += $line->lineSubtotalNative();
            $tax += $line->tax_amount_native;
            $discount += $line->discount_amount_native;
        }

        $total = $subtotal - $discount + $tax;

        $native = compact('subtotal', 'tax', 'discount', 'total');
        $base = [
            'subtotal' => $subtotal * $fxRate,
            'tax' => $tax * $fxRate,
            'discount' => $discount * $fxRate,
            'total' => $total * $fxRate,
        ];

        return [$native, $base];
    }
}
