<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteAction;
use Kanvas\Scribe\Quotes\DataTransferObject\Quote as QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLine as QuoteLineData;
use Kanvas\Scribe\Quotes\Models\Quote;
use Spatie\LaravelData\DataCollection;

/**
 * A numbered USD quote for a customer. Needs $this->kanvasApp and $this->company, as ScribeTestCase provides.
 */
trait CreatesTestQuote
{
    /**
     * @param list<QuoteLineData>|null $lines two lines (rice 10 x 20, beans 5 x 30) when left out
     */
    protected function createTestQuote(Organization $customer, ?array $lines = null): Quote
    {
        return new CreateQuoteAction(
            new QuoteData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(QuoteLineData::class, $lines ?? [
                    new QuoteLineData(description: 'Pallet of rice', quantity: 10, unit_price_native: 20, sku: 'RICE-1'),
                    new QuoteLineData(description: 'Pallet of beans', quantity: 5, unit_price_native: 30, sku: 'BEAN-1'),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                quote_number: 'PRO-' . uniqid(),
            )
        )->execute();
    }
}
