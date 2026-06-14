<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Services\QuoteStateMachineService;

/**
 * Quote validity window passed without acceptance. Typically fired by a scheduled job that sweeps SENT
 * quotes whose valid_until < today.
 */
class ExpireQuoteAction
{
    public function __construct(
        public readonly Quote $quote,
        public readonly ?UserInterface $user = null,
        protected readonly QuoteStateMachineService $stateMachine = new QuoteStateMachineService(),
    ) {
    }

    public function execute(): Quote
    {
        $this->stateMachine->assertTransition($this->quote, QuoteStatusEnum::EXPIRED);

        if ($this->quote->status === QuoteStatusEnum::EXPIRED) {
            return $this->quote;
        }

        return DB::connection('accounting')->transaction(function (): Quote {
            $quote = $this->quote;
            $quote->status = QuoteStatusEnum::EXPIRED;
            $quote->save();

            return $quote->refresh();
        });
    }
}
