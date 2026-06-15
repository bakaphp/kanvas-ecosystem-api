<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLineData;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Models\QuoteLine;

/**
 * Creates a DRAFT quote.
 *
 * Side effects:
 *   - persists quote header + lines in a single accounting-DB transaction
 *   - computes header totals from lines
 *   - DOES NOT hydrate billable snapshot — that happens at SendQuoteAction time
 *   - DOES NOT post any JE — quotes are pre-economic-event (plan §11.1)
 *
 * @see SendQuoteAction — what freezes the snapshot + flips to SENT
 */
class CreateQuoteAction
{
    public function __construct(
        public readonly QuoteData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Quote
    {
        return DB::connection('accounting')->transaction(function (): Quote {
            [$totals, $baseTotals] = $this->computeTotals();

            $quote = new Quote();
            $quote->apps_id = $this->data->app->getId();
            $quote->companies_id = $this->data->company->getId();
            $quote->quote_number = $this->data->quote_number;
            $quote->status = QuoteStatusEnum::DRAFT;
            $quote->tax_calculation_mode = $this->data->tax_calculation_mode;
            $quote->delivery_status = 'not_applicable';
            $quote->issued_date = $this->data->issued_date;
            $quote->valid_until = $this->data->valid_until;
            $quote->currency = $this->data->currency;
            $quote->fx_rate_to_base = $this->data->fx_rate_to_base;
            $quote->fx_rate_at = $this->data->issued_date ?? \Illuminate\Support\Carbon::now();
            $quote->subtotal_native = $totals['subtotal'];
            $quote->tax_native = $totals['tax'];
            $quote->discount_native = $totals['discount'];
            $quote->total_native = $totals['total'];
            $quote->subtotal_base = $baseTotals['subtotal'];
            $quote->tax_base = $baseTotals['tax'];
            $quote->discount_base = $baseTotals['discount'];
            $quote->total_base = $baseTotals['total'];
            $quote->regional_compliance = $this->data->regional_compliance;
            $quote->notes = $this->data->notes;
            $quote->internal_notes = $this->data->internal_notes;
            $quote->terms = $this->data->terms;
            $quote->source = $this->data->source;
            $quote->external_id = $this->data->external_id;
            $quote->external_url = $this->data->external_url;
            $quote->origin = $this->data->origin;
            $quote->metadata = $this->data->metadata;
            $quote->parent_quote_id = $this->data->parent_quote_id;
            $quote->revision_number = $this->data->revision_number;
            $quote->users_id = $this->user?->getId();

            if ($this->data->billable !== null) {
                $quote->customer_organization_id = $this->data->billable->getBillableId();
            }

            if ($this->data->contact !== null) {
                $quote->contact_people_id = $this->data->contact->getId();
            }

            $quote->save();

            $sortOrder = 0;
            $fxRate = (float) $this->data->fx_rate_to_base;
            foreach ($this->data->lines as $lineData) {
                /** @var QuoteLineData $lineData */
                $line = new QuoteLine();
                $line->quote_id = $quote->id;
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

            return $quote->fresh(['lines']);
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
            /** @var QuoteLineData $line */
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
