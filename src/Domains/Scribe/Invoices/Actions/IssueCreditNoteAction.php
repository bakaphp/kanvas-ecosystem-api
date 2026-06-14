<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum as SequenceDocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceTaxLineData;
use Kanvas\Scribe\Invoices\Enums\AllocationSourceTypeEnum;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Invoices\Models\InvoiceTaxLine;
use Kanvas\Scribe\Invoices\Services\InvoiceJournalEntryComposerService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;

/**
 * Issues a credit note against a parent Invoice.
 *
 * A credit note is a new row in `accounting.invoices` with:
 *   - `document_type = 'credit_note'`
 *   - `parent_invoice_id = <parent>`
 *   - `document_status = 'issued'` (credit notes skip the DRAFT stage — they're issued instantly with a JE)
 *   - amounts stored POSITIVE (the document_type discriminator + JE direction carry the credit sign)
 *
 * Side effects (all atomic in one accounting-DB transaction):
 *   1. Validate parent invoice state (must be ISSUED / SENT / PAID — not draft, not voided, not already-credit-note)
 *   2. Validate credit_amount ≤ parent.total_native (can't credit more than billed)
 *   3. Create credit-note Invoice row + lines + tax_lines
 *   4. Allocate credit-note number via DocumentNumberAllocatorService (CREDIT_NOTE document_type)
 *   5. Post the credit-note JE via composeCreditNote (DR Revenue + DR Tax Payable / CR AR)
 *   6. Insert InvoicePaymentAllocation row on parent with source_type='credit_note'
 *   7. Re-run MarkInvoicePaidAction on the parent — recomputes balance_due, flips to PAID if balance hit zero
 *
 * @see plan §7.3 — credit notes as document_type discriminator
 */
class IssueCreditNoteAction
{
    public function __construct(
        public readonly Invoice $parentInvoice,
        public readonly InvoiceData $data,
        public readonly ?UserInterface $user = null,
        protected readonly InvoiceJournalEntryComposerService $composer = new InvoiceJournalEntryComposerService(),
        protected readonly ?DocumentNumberAllocatorService $allocator = null,
    ) {
    }

    public function execute(): Invoice
    {
        $this->validateParentState();

        return DB::connection('accounting')->transaction(function (): Invoice {
            $parent = $this->parentInvoice;
            [$totals, $baseTotals] = $this->computeTotals();
            $fxRate = (float) $this->data->fx_rate_to_base;

            if ($totals['total'] <= 0) {
                throw new InvalidInvoiceTransitionException(
                    'Credit note total must be greater than zero.'
                );
            }

            if ($totals['total'] > (float) $parent->total_native + 0.0001) {
                throw new InvalidInvoiceTransitionException(
                    "Credit note total ({$totals['total']}) exceeds parent invoice total ({$parent->total_native})."
                );
            }

            $creditNote = new Invoice();
            $creditNote->apps_id = $parent->apps_id;
            $creditNote->companies_id = $parent->companies_id;
            $creditNote->document_type = DocumentTypeEnum::CREDIT_NOTE;
            $creditNote->document_status = InvoiceDocumentStatusEnum::ISSUED;
            $creditNote->collection_state = null;
            $creditNote->tax_calculation_mode = $this->data->tax_calculation_mode;
            $creditNote->delivery_status = 'not_applicable';
            $creditNote->net_terms_days = 0;
            $creditNote->issued_date = $this->data->issued_date ?? Carbon::today();
            $creditNote->due_date = null;
            $creditNote->currency = $this->data->currency;
            $creditNote->fx_rate_to_base = $fxRate;
            $creditNote->fx_rate_at = $this->data->issued_date ?? Carbon::now();
            $creditNote->subtotal_native = $totals['subtotal'];
            $creditNote->tax_native = $totals['tax'];
            $creditNote->discount_native = $totals['discount'];
            $creditNote->total_native = $totals['total'];
            $creditNote->paid_native = 0.0;
            $creditNote->balance_due_native = 0.0;          // credit notes don't have a balance owed
            $creditNote->subtotal_base = $baseTotals['subtotal'];
            $creditNote->tax_base = $baseTotals['tax'];
            $creditNote->discount_base = $baseTotals['discount'];
            $creditNote->total_base = $baseTotals['total'];
            $creditNote->paid_base = 0.0;
            $creditNote->balance_due_base = 0.0;
            $creditNote->tax_metadata = $this->data->tax_metadata;
            $creditNote->regional_compliance = $this->data->regional_compliance;
            $creditNote->notes = $this->data->notes;
            $creditNote->internal_notes = $this->data->internal_notes;
            $creditNote->terms = $this->data->terms;
            $creditNote->parent_invoice_id = $parent->id;
            $creditNote->source = $this->data->source;
            $creditNote->external_id = $this->data->external_id;
            $creditNote->external_url = $this->data->external_url;
            $creditNote->origin = $this->data->origin;
            $creditNote->metadata = $this->data->metadata;
            $creditNote->users_id = $this->user?->getId();

            // Inherit billable snapshot from parent (frozen — same customer the credit goes to)
            $creditNote->billable_type = $parent->billable_type;
            $creditNote->billable_id = $parent->billable_id;
            $creditNote->billable_display_name = $parent->billable_display_name;
            $creditNote->billable_legal_name = $parent->billable_legal_name;
            $creditNote->billable_tax_id = $parent->billable_tax_id;
            $creditNote->billable_email = $parent->billable_email;
            $creditNote->billing_address_snapshot = $parent->billing_address_snapshot;
            $creditNote->shipping_address_snapshot = $parent->shipping_address_snapshot;

            $creditNote->save();

            $this->persistLines($creditNote, $fxRate);
            $this->persistTaxLines($creditNote, $fxRate);

            $this->allocateCreditNoteNumber($creditNote);
            $creditNote->save();

            $creditNote->refresh();
            $creditNote->load(['lines', 'taxLines']);

            $jeData = $this->composer->composeCreditNote($creditNote);
            new PostJournalEntryAction(
                data: $jeData,
                postedByUser: $this->user,
            )->execute();

            $this->writeAllocationOnParent($parent, $creditNote);

            new MarkInvoicePaidAction(
                invoice: $parent,
                user: $this->user,
            )->execute();

            $creditNote->emitLedgerEvent(
                eventType: 'scribe.credit_note.issued',
                payload: [
                    'credit_note_number' => $creditNote->invoice_number,
                    'parent_invoice_id' => $parent->id,
                    'parent_invoice_number' => $parent->invoice_number,
                    'currency' => $creditNote->currency,
                    'total_native' => (float) $creditNote->total_native,
                    'total_base' => (float) $creditNote->total_base,
                ],
            );

            return $creditNote->refresh();
        });
    }

    private function validateParentState(): void
    {
        $parent = $this->parentInvoice;

        if ($parent->document_type === DocumentTypeEnum::CREDIT_NOTE) {
            throw new InvalidInvoiceTransitionException(
                "Cannot issue a credit note against another credit note (parent id={$parent->id})."
            );
        }

        $validParentStatuses = [
            InvoiceDocumentStatusEnum::ISSUED,
            InvoiceDocumentStatusEnum::SENT,
            InvoiceDocumentStatusEnum::PAID,
        ];

        if (! in_array($parent->document_status, $validParentStatuses, true)) {
            throw new InvalidInvoiceTransitionException(
                "Cannot credit invoice {$parent->id} in status '{$parent->document_status->value}'. "
                . 'Parent must be issued, sent, or paid.'
            );
        }
    }

    private function persistLines(Invoice $creditNote, float $fxRate): void
    {
        $sortOrder = 0;
        foreach ($this->data->lines as $lineData) {
            /** @var InvoiceLineData $lineData */
            $line = new InvoiceLine();
            $line->invoice_id = $creditNote->id;
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
    }

    private function persistTaxLines(Invoice $creditNote, float $fxRate): void
    {
        if ($this->data->taxLines === null) {
            return;
        }

        foreach ($this->data->taxLines as $taxLineData) {
            /** @var InvoiceTaxLineData $taxLineData */
            $taxLine = new InvoiceTaxLine();
            $taxLine->invoice_id = $creditNote->id;
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

    private function allocateCreditNoteNumber(Invoice $creditNote): void
    {
        if ($creditNote->invoice_number !== null && $creditNote->invoice_number !== '') {
            return;
        }

        $allocator = $this->allocator ?? new DocumentNumberAllocatorService();
        $creditNote->invoice_number = $allocator->allocate(
            $creditNote->apps_id,
            $creditNote->companies_id,
            SequenceDocumentTypeEnum::CREDIT_NOTE,
            defaultPrefix: 'CRN-',
        );
    }

    private function writeAllocationOnParent(Invoice $parent, Invoice $creditNote): void
    {
        $allocation = new InvoicePaymentAllocation();
        $allocation->apps_id = $parent->apps_id;
        $allocation->companies_id = $parent->companies_id;
        $allocation->invoice_id = $parent->id;
        $allocation->payment_id = null;
        $allocation->source_type = AllocationSourceTypeEnum::CREDIT_NOTE->value;
        $allocation->source_id = $creditNote->id;
        $allocation->status = AllocationStatusEnum::ACTIVE->value;
        $allocation->amount_native = (float) $creditNote->total_native;
        $allocation->amount_base = (float) $creditNote->total_base;
        $allocation->currency = $creditNote->currency;
        $allocation->fx_rate_to_base = (float) $creditNote->fx_rate_to_base;
        $allocation->fx_rate_at = $creditNote->fx_rate_at;
        $allocation->allocated_at = Carbon::now();
        $allocation->allocated_by_users_id = $this->user?->getId();
        $allocation->source = 'kanvas';
        $allocation->save();
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
