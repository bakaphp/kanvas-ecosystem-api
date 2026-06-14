<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Services\QuoteStateMachine;

/**
 * Customer accepted the quote — flips status to ACCEPTED + stamps accepted_at.
 *
 * Next step is typically ConvertQuoteToInvoiceAction (the only valid transition out of ACCEPTED).
 */
class AcceptQuoteAction
{
    public function __construct(
        public readonly Quote $quote,
        public readonly ?UserInterface $user = null,
        protected readonly QuoteStateMachine $stateMachine = new QuoteStateMachine(),
    ) {
    }

    public function execute(): Quote
    {
        $this->stateMachine->assertTransition($this->quote, QuoteStatusEnum::ACCEPTED);

        if ($this->quote->status === QuoteStatusEnum::ACCEPTED) {
            return $this->quote;
        }

        return DB::connection('accounting')->transaction(function () {
            $quote = $this->quote;
            $quote->status = QuoteStatusEnum::ACCEPTED;
            $quote->accepted_at = Carbon::now();
            $quote->save();

            return $quote->refresh();
        });
    }
}
