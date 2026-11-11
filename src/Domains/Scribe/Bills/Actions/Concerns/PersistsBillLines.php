<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions\Concerns;

use Baka\Support\Money;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\DataTransferObject\BillTaxLine as BillTaxLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillLine;
use Kanvas\Scribe\Bills\Models\BillTaxLine;
use Kanvas\Scribe\Support\DocumentTotals;

trait PersistsBillLines
{
    protected function persistLines(Bill $bill, BillData $data): void
    {
        $fxRate = $data->fx_rate_to_base;
        $sortOrder = 0;

        foreach ($data->lines as $lineData) {
            /** @var BillLineData $lineData */
            $line = new BillLine();
            $line->bill_id = $bill->id;
            $line->sort_order = $lineData->sort_order ?? $sortOrder++;
            $line->item_id = $lineData->item_id;
            $line->sku = $lineData->sku;
            $line->description = $lineData->description;
            $line->quantity = $lineData->quantity;
            $line->unit_price_native = $lineData->unit_price_native;
            $line->unit_price_base = Money::toBase($lineData->unit_price_native, $fxRate);
            $line->discount_amount_native = $lineData->discount_amount_native;
            $line->discount_amount_base = Money::toBase($lineData->discount_amount_native, $fxRate);
            $line->discount_rate = $lineData->discount_rate;
            $line->tax_rate = $lineData->tax_rate;
            $line->tax_amount_native = $lineData->tax_amount_native;
            $line->tax_amount_base = Money::toBase($lineData->tax_amount_native, $fxRate);
            $line->line_total_native = $lineData->lineTotalNative();
            $line->line_total_base = Money::toBase($lineData->lineTotalNative(), $fxRate);
            $line->expense_account_id = $lineData->expense_account_id;
            $line->subaccount_id = $lineData->subaccount_id;
            $line->tax_metadata = $lineData->tax_metadata;
            $line->class_id = $lineData->class_id;
            $line->department_id = $lineData->department_id;
            $line->metadata = $lineData->metadata;
            $line->save();
        }
    }

    protected function persistTaxLines(Bill $bill, BillData $data): void
    {
        foreach ($data->taxLines ?? [] as $taxLineData) {
            /** @var BillTaxLineData $taxLineData */
            $taxLine = new BillTaxLine();
            $taxLine->bill_id = $bill->id;
            $taxLine->tax_code_id = $taxLineData->tax_code_id;
            $taxLine->name = $taxLineData->name;
            $taxLine->tax_rate = $taxLineData->tax_rate;
            $taxLine->jurisdiction = $taxLineData->jurisdiction;
            $taxLine->tax_amount_native = $taxLineData->tax_amount_native;
            $taxLine->tax_amount_base = Money::toBase($taxLineData->tax_amount_native, $data->fx_rate_to_base);
            $taxLine->metadata = $taxLineData->metadata;
            $taxLine->save();
        }
    }

    /**
     * @return array{0: array{subtotal: float, tax: float, discount: float, total: float}, 1: array{subtotal: float, tax: float, discount: float, total: float}}
     */
    protected function computeTotals(BillData $data): array
    {
        return DocumentTotals::compute(
            array_map(
                static fn (BillLineData $line): array => [
                    'subtotal' => $line->lineSubtotalNative(),
                    'tax' => $line->tax_amount_native,
                    'discount' => $line->discount_amount_native,
                ],
                $data->lines->all(),
            ),
            $data->fx_rate_to_base,
        );
    }
}
