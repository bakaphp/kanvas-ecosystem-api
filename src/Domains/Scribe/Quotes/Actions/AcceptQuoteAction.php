<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\BillingSchedules\Actions\CreateBillingSchedulesFromQuoteAction;
use Kanvas\Scribe\BillingSchedules\Jobs\RunBillingScheduleJob;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Services\QuoteStateMachineService;

/**
 * Customer accepted the quote — flips status to ACCEPTED + stamps accepted_at.
 *
 * A quote with a SOW is billed from the SOW's payment schedule instead of being converted whole:
 * acceptance creates one billing schedule per payment line and bills the `on_signature` ones right
 * away. Re-accepting runs the same idempotent step, so a schedule missed by a failed first attempt is
 * still created and nothing is created twice; a schedule that already existed is left to the daily run.
 *
 * A quote without a SOW goes on to ConvertQuoteToInvoiceAction.
 */
class AcceptQuoteAction
{
    public function __construct(
        public readonly Quote $quote,
        public readonly ?UserInterface $user = null,
        protected readonly QuoteStateMachineService $stateMachine = new QuoteStateMachineService(),
    ) {
    }

    public function execute(): Quote
    {
        $this->stateMachine->assertTransition($this->quote, QuoteStatusEnum::ACCEPTED);

        if ($this->quote->status !== QuoteStatusEnum::ACCEPTED) {
            DB::connection('accounting')->transaction(function (): void {
                $this->quote->status = QuoteStatusEnum::ACCEPTED;
                $this->quote->accepted_at = Carbon::now();
                $this->quote->save();
            });
        }

        $this->startBilling();

        return $this->quote->refresh();
    }

    private function startBilling(): void
    {
        $schedules = new CreateBillingSchedulesFromQuoteAction($this->quote, $this->user)->execute();
        $today = Carbon::today();

        foreach ($schedules as $schedule) {
            if ($schedule->wasRecentlyCreated && $schedule->isDue($today)) {
                RunBillingScheduleJob::dispatch($this->quote->app, $schedule, $today->toDateString())->afterCommit();
            }
        }
    }
}
