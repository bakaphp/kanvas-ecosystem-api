<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Actions\EnsurePersonOrganizationAction;
use Kanvas\Scribe\Delivery\Actions\OpenDocumentEngagementAction;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentLink;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Quotes\DataTransferObject\Quote as QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLine;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

/**
 * A quote for one person, ready to hand over: numbered, and open as the tracked page they are given
 * the link to. It contacts nobody — whoever is talking to the person passes the link on.
 */
final class CreatePersonQuoteAction
{
    public ?DocumentLink $link = null;

    /**
     * @param DataCollection<int, QuoteLine> $lines
     */
    public function __construct(
        private readonly People $people,
        private readonly DataCollection $lines,
        private readonly string $currency,
        private readonly Users $user,
        private readonly ?string $notes = null,
    ) {
    }

    public function execute(): Quote
    {
        $customer = new EnsurePersonOrganizationAction($this->people)->execute();

        $draft = new CreateQuoteAction(
            data: new QuoteData(
                app: $this->people->app,
                company: $this->people->company,
                billable: $customer,
                lines: $this->lines,
                currency: $this->currency,
                fx_rate_to_base: 1.0,
                issued_date: Carbon::today(),
                notes: $this->notes,
                contact: $this->people,
            ),
            user: $this->user,
        )->execute();

        // Before the quote is numbered: a sent quote nobody can be given a link to is a dead end.
        $this->page($draft)->assertSetUp();

        $quote = new SendQuoteAction(
            quote: $draft,
            billable: $customer,
            user: $this->user,
        )->execute();

        $this->link = $this->page($quote)->execute();

        return $quote;
    }

    private function page(Quote $quote): OpenDocumentEngagementAction
    {
        return new OpenDocumentEngagementAction(
            document: $quote,
            people: $this->people,
            user: $this->user,
            action: DocumentActionEnum::forDocument($quote),
        );
    }
}
