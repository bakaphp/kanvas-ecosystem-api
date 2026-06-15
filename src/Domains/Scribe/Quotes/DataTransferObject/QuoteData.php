<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\BillableInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Typed payload CreateQuoteAction consumes.
 *
 * @property DataCollection<QuoteLineData> $lines
 */
class QuoteData extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?BillableInterface $billable,
        /** @var DataCollection<QuoteLineData> */
        public readonly DataCollection $lines,
        public readonly string $currency,
        public readonly float $fx_rate_to_base,
        public readonly ?string $quote_number = null,
        public readonly ?Carbon $issued_date = null,
        public readonly ?Carbon $valid_until = null,
        public readonly ?string $notes = null,
        public readonly ?string $internal_notes = null,
        public readonly ?string $terms = null,
        public readonly ?array $regional_compliance = null,
        public readonly ?array $metadata = null,
        public readonly string $tax_calculation_mode = 'exclusive',
        public readonly string $source = 'kanvas',
        public readonly ?string $external_id = null,
        public readonly ?string $external_url = null,
        public readonly JournalEntryOriginEnum $origin = JournalEntryOriginEnum::KANVAS,
        public readonly ?int $parent_quote_id = null,
        public readonly int $revision_number = 1,
        /**
         * The human at the customer Organization the quote was addressed to (e.g. "Attn: John Doe").
         * The legal customer is still `$billable` (always an Organization); this is the recipient.
         * Optional — quotes that go to an organization mailbox / no specific person leave it null.
         */
        public readonly ?People $contact = null,
    ) {
    }
}
