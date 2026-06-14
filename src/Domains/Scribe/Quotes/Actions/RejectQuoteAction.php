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
 * Customer rejected the quote. Captures lost_reason for the lost-quote analytics (plan §11.1).
 */
class RejectQuoteAction
{
    public function __construct(
        public readonly Quote $quote,
        public readonly ?string $lostReason = null,
        public readonly ?UserInterface $user = null,
        protected readonly QuoteStateMachine $stateMachine = new QuoteStateMachine(),
    ) {
    }

    public function execute(): Quote
    {
        $this->stateMachine->assertTransition($this->quote, QuoteStatusEnum::REJECTED);

        if ($this->quote->status === QuoteStatusEnum::REJECTED) {
            return $this->quote;
        }

        return DB::connection('accounting')->transaction(function () {
            $quote = $this->quote;
            $quote->status = QuoteStatusEnum::REJECTED;
            $quote->rejected_at = Carbon::now();
            $quote->lost_reason = $this->lostReason;
            $quote->save();

            return $quote->refresh();
        });
    }
}
