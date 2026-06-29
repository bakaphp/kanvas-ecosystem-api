<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\DataTransferObject\BillTaxLine as BillTaxLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Exceptions\InvalidBillTransitionException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillLine;
use Kanvas\Scribe\Bills\Models\BillTaxLine;

class UpdateBillAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly BillData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Bill
    {
        if ($this->bill->document_status !== BillDocumentStatusEnum::DRAFT) {
            throw new InvalidBillTransitionException(
                "Bill {$this->bill->id} cannot be updated — status is "
                . "'{$this->bill->document_status->value}'. Only draft bills are editable. "
                . 'Void + re-create for post-receive changes.'
            );
        }

        return DB::connection('accounting')->transaction(function (): Bill {
            $bill = $this->bill;
            [$totals, $baseTotals] = $this->computeTotals();
            $fxRate = $this->data->fx_rate_to_base;

            $bill->bill_number = $this->data->bill_number;
            $bill->tax_calculation_mode = $this->data->tax_calculation_mode;
            $bill->net_terms_days = $this->data->net_terms_days ?? 0;
            $bill->bill_date = $this->data->bill_date;
            $bill->received_date = $this->data->received_date;
            $bill->due_date = $this->data->due_date;
            $bill->scheduled_payment_date = $this->data->scheduled_payment_date;
            $bill->currency = $this->data->currency;
            $bill->fx_rate_to_base = $fxRate;
            $bill->fx_rate_at = $this->data->bill_date ?? Carbon::now();
            $bill->subtotal_native = $totals['subtotal'];
            $bill->tax_native = $totals['tax'];
            $bill->discount_native = $totals['discount'];
            $bill->total_native = $totals['total'];
            $bill->balance_due_native = $totals['total'];
            $bill->subtotal_base = $baseTotals['subtotal'];
            $bill->tax_base = $baseTotals['tax'];
            $bill->discount_base = $baseTotals['discount'];
            $bill->total_base = $baseTotals['total'];
            $bill->balance_due_base = $baseTotals['total'];
            $bill->tax_metadata = $this->data->tax_metadata;
            $bill->regional_compliance = $this->data->regional_compliance;
            $bill->notes = $this->data->notes;
            $bill->internal_notes = $this->data->internal_notes;
            $bill->terms = $this->data->terms;
            $bill->purchase_order_id = $this->data->purchase_order_id;
            $bill->metadata = $this->data->metadata;

            // Vendor reference — allow swap (or clear) while in draft. This is the primary motivator
            // for this Action: PDF-ingested drafts land without a resolved vendor; operators need to
            // attach one before Receive. Don't update display_name/legal_name/tax_id/email — those
            // freeze at Receive time from the Guild Org snapshot.
            if ($this->data->vendor !== null) {
                $bill->vendor_organization_id = $this->data->vendor->getPayeeId();
            } else {
                $bill->vendor_organization_id = null;
            }

            $bill->vendor_display_name = $this->data->vendor_display_name;
            $bill->vendor_legal_name = $this->data->vendor_legal_name;
            $bill->vendor_tax_id = $this->data->vendor_tax_id;
            $bill->vendor_email = $this->data->vendor_email;
            $bill->vendor_address_snapshot = $this->data->vendor_address_snapshot;

            $bill->save();

            // Lines + tax lines — replace wholesale
            BillLine::query()->where('bill_id', $bill->id)->delete();
            BillTaxLine::query()->where('bill_id', $bill->id)->delete();

            $sortOrder = 0;
            foreach ($this->data->lines as $lineData) {
                /** @var BillLineData $lineData */
                $line = new BillLine();
                $line->bill_id = $bill->id;
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
                $line->expense_account_id = $lineData->expense_account_id;
                $line->tax_metadata = $lineData->tax_metadata;
                $line->class_id = $lineData->class_id;
                $line->department_id = $lineData->department_id;
                $line->metadata = $lineData->metadata;
                $line->save();
            }

            if ($this->data->taxLines !== null) {
                foreach ($this->data->taxLines as $taxLineData) {
                    /** @var BillTaxLineData $taxLineData */
                    $taxLine = new BillTaxLine();
                    $taxLine->bill_id = $bill->id;
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

            $bill->load(['lines', 'taxLines']);

            return $bill;
        });
    }

    /**
     * @return array{0: array{subtotal: float, tax: float, discount: float, total: float}, 1: array{subtotal: float, tax: float, discount: float, total: float}}
     */
    private function computeTotals(): array
    {
        $fxRate = $this->data->fx_rate_to_base;
        $subtotal = 0.0;
        $tax = 0.0;
        $discount = 0.0;

        foreach ($this->data->lines as $line) {
            /** @var BillLineData $line */
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
