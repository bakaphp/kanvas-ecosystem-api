<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocator;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceiptData;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceiptLineData;
use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceiptLine;
use Kanvas\Scribe\SalesReceipts\Services\SalesReceiptJournalEntryComposer;

/**
 * Creates a sales receipt atomically — the sale already happened, so the receipt is RECORDED on creation
 * and the JE posts immediately. No draft state, no separate "send" step.
 *
 * Side effects (single accounting-DB transaction):
 *   1. Compute header totals from line data
 *   2. Freeze billable snapshot (sale is the economic event)
 *   3. Allocate receipt_number via DocumentNumberAllocator
 *   4. Persist receipt header + lines
 *   5. Compose JE (DR Cash / CR Revenue + CR Sales Tax Payable)
 *   6. Post the JE — fiscal period gate + balance validator both run inside PostJournalEntryAction
 *
 * @see plan §11.3 — Sales Receipt worked example
 */
class CreateSalesReceiptAction
{
    public function __construct(
        public readonly SalesReceiptData $data,
        public readonly ?UserInterface $user = null,
        protected readonly SalesReceiptJournalEntryComposer $composer = new SalesReceiptJournalEntryComposer(),
        protected readonly ?DocumentNumberAllocator $allocator = null,
    ) {
    }

    public function execute(): SalesReceipt
    {
        return DB::connection('accounting')->transaction(function (): SalesReceipt {
            [$totals, $baseTotals] = $this->computeTotals();
            $fxRate = (float) $this->data->fx_rate_to_base;

            $receipt = new SalesReceipt();
            $receipt->apps_id = $this->data->app->getId();
            $receipt->companies_id = $this->data->company->getId();
            $receipt->status = SalesReceiptStatusEnum::RECORDED;
            $receipt->receipt_date = $this->data->receipt_date;
            $receipt->tax_calculation_mode = $this->data->tax_calculation_mode;
            $receipt->currency = $this->data->currency;
            $receipt->fx_rate_to_base = $this->data->fx_rate_to_base;
            $receipt->fx_rate_at = $this->data->receipt_date;
            $receipt->subtotal_native = $totals['subtotal'];
            $receipt->tax_native = $totals['tax'];
            $receipt->discount_native = $totals['discount'];
            $receipt->total_native = $totals['total'];
            $receipt->subtotal_base = $baseTotals['subtotal'];
            $receipt->tax_base = $baseTotals['tax'];
            $receipt->discount_base = $baseTotals['discount'];
            $receipt->total_base = $baseTotals['total'];
            $receipt->tax_metadata = $this->data->tax_metadata;
            $receipt->regional_compliance = $this->data->regional_compliance;
            $receipt->cash_account_id = $this->data->cash_account_id;
            $receipt->payment_method_id = $this->data->payment_method_id;
            $receipt->payment_id = $this->data->payment_id;
            $receipt->notes = $this->data->notes;
            $receipt->internal_notes = $this->data->internal_notes;
            $receipt->source = $this->data->source;
            $receipt->external_id = $this->data->external_id;
            $receipt->external_url = $this->data->external_url;
            $receipt->origin = $this->data->origin;
            $receipt->metadata = $this->data->metadata;
            $receipt->users_id = $this->user?->getId();

            // Billable + snapshot — frozen immediately (no draft phase)
            $receipt->billable_type = $this->data->billable->getBillableType();
            $receipt->billable_id = $this->data->billable->getBillableId();
            $receipt->billable_display_name = $this->data->billable->getBillableDisplayName();
            $receipt->billable_legal_name = $this->data->billable->getBillableLegalName();
            $receipt->billable_tax_id = $this->data->billable->getBillableTaxId();
            $receipt->billable_email = $this->data->billable->getBillingEmail();
            $receipt->billing_address_snapshot = $this->data->billable->getBillingAddressArray();

            $receipt->receipt_number = $this->data->receipt_number
                ?? $this->resolveAllocator()->allocate(
                    $receipt->apps_id,
                    $receipt->companies_id,
                    DocumentTypeEnum::SALES_RECEIPT,
                    defaultPrefix: '',
                );

            $receipt->save();

            $sortOrder = 0;
            foreach ($this->data->lines as $lineData) {
                /** @var SalesReceiptLineData $lineData */
                $line = new SalesReceiptLine();
                $line->sales_receipt_id = $receipt->id;
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
                $line->income_account_id = $lineData->income_account_id;
                $line->class_id = $lineData->class_id;
                $line->department_id = $lineData->department_id;
                $line->metadata = $lineData->metadata;
                $line->save();
            }

            // Compose + post the JE — sales receipt IS the economic event, so JE posts immediately
            $receipt->refresh();
            $jeData = $this->composer->composeCreate($receipt);
            new PostJournalEntryAction(
                data: $jeData,
                postedByUser: $this->user,
            )->execute();

            $receipt->emitLedgerEvent(
                eventType: 'scribe.sales_receipt.recorded',
                payload: [
                    'receipt_number' => $receipt->receipt_number,
                    'billable_type' => $receipt->billable_type,
                    'billable_id' => $receipt->billable_id,
                    'billable_display_name' => $receipt->billable_display_name,
                    'currency' => $receipt->currency,
                    'total_native' => (float) $receipt->total_native,
                    'total_base' => (float) $receipt->total_base,
                ],
            );

            return $receipt->refresh();
        });
    }

    private function resolveAllocator(): DocumentNumberAllocator
    {
        return $this->allocator ?? new DocumentNumberAllocator();
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
            /** @var SalesReceiptLineData $line */
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
