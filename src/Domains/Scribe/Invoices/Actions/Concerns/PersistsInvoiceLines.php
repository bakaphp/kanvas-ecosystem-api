<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions\Concerns;

use Baka\Support\Money;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceTaxLine as InvoiceTaxLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Kanvas\Scribe\Invoices\Models\InvoiceTaxLine;

trait PersistsInvoiceLines
{
    protected function persistLines(Invoice $invoice, InvoiceData $data): void
    {
        $fxRate = $data->fx_rate_to_base;
        $sortOrder = 0;

        foreach ($data->lines as $lineData) {
            /** @var InvoiceLineData $lineData */
            $line = new InvoiceLine();
            $line->invoice_id = $invoice->id;
            $line->sort_order = $lineData->sort_order ?? $sortOrder++;
            $line->item_id = $lineData->item_id;
            $line->account_id = $lineData->account_id;
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
            $line->tax_metadata = $lineData->tax_metadata;
            $line->class_id = $lineData->class_id;
            $line->department_id = $lineData->department_id;
            $line->metadata = $lineData->metadata;
            $line->save();
        }
    }

    protected function persistTaxLines(Invoice $invoice, InvoiceData $data): void
    {
        foreach ($data->taxLines ?? [] as $taxLineData) {
            /** @var InvoiceTaxLineData $taxLineData */
            $taxLine = new InvoiceTaxLine();
            $taxLine->invoice_id = $invoice->id;
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
}
