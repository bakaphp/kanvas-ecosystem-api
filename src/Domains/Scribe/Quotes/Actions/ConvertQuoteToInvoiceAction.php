<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Services\QuoteStateMachine;
use Spatie\LaravelData\DataCollection;

/**
 * Converts an ACCEPTED quote into a DRAFT invoice.
 *
 * Atomic side effects:
 *   1. State-machine assert (accepted → converted)
 *   2. Map quote lines → invoice lines (preserving prices, taxes, discounts)
 *   3. Create draft invoice via CreateInvoiceAction (no JE yet — caller still has to issue)
 *   4. Stamp quote.converted_to_invoice_id + quote.status = CONVERTED
 *
 * Returns the new Invoice. The caller typically follows with IssueInvoiceAction to post the JE.
 *
 * @see plan §11.1 — final step of BrightStar Foods worked example
 */
class ConvertQuoteToInvoiceAction
{
    public function __construct(
        public readonly Quote $quote,
        public readonly ?int $netTermsDays = 30,
        public readonly ?UserInterface $user = null,
        protected readonly QuoteStateMachine $stateMachine = new QuoteStateMachine(),
    ) {
    }

    public function execute(): Invoice
    {
        $this->stateMachine->assertTransition($this->quote, QuoteStatusEnum::CONVERTED);

        return DB::connection('accounting')->transaction(function () {
            $quote = $this->quote;

            $invoiceLineData = $quote->lines->map(function ($line) {
                return new InvoiceLineData(
                    description: (string) $line->description,
                    quantity: (float) $line->quantity,
                    unit_price_native: (float) $line->unit_price_native,
                    item_id: $line->item_id,
                    sku: $line->sku,
                    sort_order: $line->sort_order,
                    discount_rate: $line->discount_rate,
                    discount_amount_native: (float) $line->discount_amount_native,
                    tax_rate: $line->tax_rate,
                    tax_amount_native: (float) $line->tax_amount_native,
                    tax_metadata: $line->tax_metadata,
                    class_id: $line->class_id,
                    department_id: $line->department_id,
                    metadata: $line->metadata,
                );
            })->all();

            $invoiceData = new InvoiceData(
                app: $quote->app,
                company: $quote->company,
                billable: null,                                  // billable_type/id copied from quote; snapshot freezes at Issue
                lines: new DataCollection(InvoiceLineData::class, $invoiceLineData),
                currency: $quote->currency,
                fx_rate_to_base: (float) $quote->fx_rate_to_base,
                net_terms_days: $this->netTermsDays,
                notes: $quote->notes,
                internal_notes: $quote->internal_notes,
                terms: $quote->terms,
                quote_id: $quote->id,
                regional_compliance: $quote->regional_compliance,
                tax_calculation_mode: $quote->tax_calculation_mode,
                source: 'kanvas',                                // converted invoices originate in Kanvas regardless of quote origin
                metadata: $quote->metadata,
            );

            $invoice = new CreateInvoiceAction(
                data: $invoiceData,
                user: $this->user,
            )->execute();

            // The InvoiceData carried null billable; copy the polymorphic FK from the quote so IssueInvoiceAction
            // can later look up the live Guild model and freeze its snapshot.
            $invoice->billable_type = $quote->billable_type;
            $invoice->billable_id = $quote->billable_id;
            $invoice->save();

            $quote->status = QuoteStatusEnum::CONVERTED;
            $quote->converted_to_invoice_id = $invoice->id;
            $quote->save();

            return $invoice->refresh();
        });
    }
}
