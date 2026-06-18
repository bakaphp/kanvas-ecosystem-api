<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceTaxLine as InvoiceTaxLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Kanvas\Scribe\Invoices\Models\InvoiceTaxLine;

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
    public function __construct(
        public readonly Invoice $invoice,
        public readonly InvoiceData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Invoice
    {
        if ($this->invoice->document_status !== InvoiceDocumentStatusEnum::DRAFT) {
            throw new InvalidInvoiceTransitionException(
                "Invoice {$this->invoice->id} cannot be updated — status is "
                . "'{$this->invoice->document_status->value}'. Only draft invoices are editable. "
                . 'Use AmendInvoiceAction / IssueCreditNoteAction / VoidInvoiceAction for post-issue changes.'
            );
        }

        return DB::connection('accounting')->transaction(function (): Invoice {
            $invoice = $this->invoice;
            [$totals, $baseTotals] = $this->computeTotals();
            $fxRate = (float) $this->data->fx_rate_to_base;

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

            // Billable reference — allow swap (or clear) while in draft
            if ($this->data->billable !== null) {
                $invoice->customer_organization_id = $this->data->billable->getBillableId();
            } else {
                $invoice->customer_organization_id = null;
            }

            $invoice->save();

            // Lines + tax lines — replace wholesale
            InvoiceLine::query()->where('invoice_id', $invoice->id)->delete();
            InvoiceTaxLine::query()->where('invoice_id', $invoice->id)->delete();

            $sortOrder = 0;
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
