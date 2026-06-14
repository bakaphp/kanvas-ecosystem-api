<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteData;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Services\QuoteStateMachine;

/**
 * Creates a new revision of an existing quote.
 *
 * Customer says "can you redo it with a 10% discount?" → this Action creates a new Quote with the changes,
 * links it to the parent via parent_quote_id, increments revision_number, and moves the parent to SUPERSEDED.
 *
 * Constraints:
 *   - Original quote must be in DRAFT or SENT state (canBeRevised). Already-accepted / rejected / expired
 *     / converted / superseded quotes can't be revised — issue a new fresh quote instead.
 *   - New revision starts as DRAFT (caller typically calls SendQuoteAction next).
 *
 * @see plan §11.1 — BrightStar Foods revision scenario
 */
class CreateQuoteRevisionAction
{
    public function __construct(
        public readonly Quote $originalQuote,
        public readonly QuoteData $newRevisionData,
        public readonly ?UserInterface $user = null,
        protected readonly QuoteStateMachine $stateMachine = new QuoteStateMachine(),
    ) {
    }

    public function execute(): Quote
    {
        if (! $this->originalQuote->status->canBeRevised()) {
            throw new InvalidQuoteTransitionException(
                "Quote {$this->originalQuote->id} (status '{$this->originalQuote->status->value}') "
                . 'cannot be revised. Only DRAFT and SENT quotes can. Issue a new quote instead.'
            );
        }

        return DB::connection('accounting')->transaction(function (): Quote {
            // Build a new QuoteData with parent_quote_id + revision_number wired up.
            // (Caller's $newRevisionData provides the changes; we override the chain fields.)
            $revisionData = new QuoteData(
                app: $this->newRevisionData->app,
                company: $this->newRevisionData->company,
                billable: $this->newRevisionData->billable,
                lines: $this->newRevisionData->lines,
                currency: $this->newRevisionData->currency,
                fx_rate_to_base: $this->newRevisionData->fx_rate_to_base,
                quote_number: null,                            // allocated fresh at Send time
                issued_date: $this->newRevisionData->issued_date,
                valid_until: $this->newRevisionData->valid_until,
                notes: $this->newRevisionData->notes,
                internal_notes: $this->newRevisionData->internal_notes,
                terms: $this->newRevisionData->terms,
                regional_compliance: $this->newRevisionData->regional_compliance,
                metadata: $this->newRevisionData->metadata,
                tax_calculation_mode: $this->newRevisionData->tax_calculation_mode,
                source: $this->newRevisionData->source,
                external_id: $this->newRevisionData->external_id,
                external_url: $this->newRevisionData->external_url,
                origin: $this->newRevisionData->origin,
                parent_quote_id: $this->originalQuote->id,
                revision_number: $this->originalQuote->revision_number + 1,
            );

            $newQuote = new CreateQuoteAction(
                data: $revisionData,
                user: $this->user,
            )->execute();

            // Move the original to SUPERSEDED so the "live" version is unambiguous.
            $this->stateMachine->assertTransition($this->originalQuote, QuoteStatusEnum::SUPERSEDED);
            $this->originalQuote->status = QuoteStatusEnum::SUPERSEDED;
            $this->originalQuote->save();

            return $newQuote->refresh();
        });
    }
}
