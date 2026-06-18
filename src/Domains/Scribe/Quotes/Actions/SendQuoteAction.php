<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Actions;

use Baka\Contracts\BillableInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Services\QuoteStateMachineService;

/**
 * Transitions a draft quote into SENT state.
 *
 * Atomic side effects (wrapped in the accounting-DB transaction):
 *   1. State-machine assert (draft → sent)
 *   2. Hydrate billable snapshot from the live Guild model (immutable post-send per plan §7.4)
 *   3. Allocate quote_number via DocumentNumberAllocatorService
 *   4. Set sent_at = now; if valid_until is null, default to issued_date + 30 days
 *   5. Flip status to SENT
 *
 * No JE — quotes are pre-economic-event.
 *
 * @see plan §11.1 — quote → revision → accept → convert worked example
 */
class SendQuoteAction
{
    public function __construct(
        public readonly Quote $quote,
        public readonly BillableInterface $billable,
        public readonly ?UserInterface $user = null,
        protected readonly QuoteStateMachineService $stateMachine = new QuoteStateMachineService(),
        protected readonly ?DocumentNumberAllocatorService $allocator = null,
    ) {
    }

    public function execute(): Quote
    {
        $this->stateMachine->assertTransition($this->quote, QuoteStatusEnum::SENT);

        if ($this->quote->status === QuoteStatusEnum::SENT) {
            return $this->quote;
        }

        return DB::connection('accounting')->transaction(function (): Quote {
            $quote = $this->quote;

            $this->freezeBillableSnapshot($quote, $this->billable);
            $this->setDates($quote);
            $this->allocateQuoteNumberIfMissing($quote);

            $quote->status = QuoteStatusEnum::SENT;
            $quote->delivery_status = 'sent';
            $quote->sent_at = Carbon::now();
            $quote->save();

            return $quote->refresh();
        });
    }

    private function freezeBillableSnapshot(Quote $quote, BillableInterface $billable): void
    {
        $quote->customer_organization_id = $billable->getBillableId();
        $quote->billable_display_name = $billable->getBillableDisplayName();
        $quote->billable_legal_name = $billable->getBillableLegalName();
        $quote->billable_tax_id = $billable->getBillableTaxId();
        $quote->billable_email = $billable->getBillingEmail();
        $quote->billing_address_snapshot = $billable->getBillingAddressArray();
    }

    private function setDates(Quote $quote): void
    {
        $today = Carbon::today();
        if ($quote->issued_date === null) {
            $quote->issued_date = $today;
        }

        if ($quote->valid_until === null) {
            $quote->valid_until = $quote->issued_date->copy()->addDays(30);   // 30-day default validity
        }
    }

    private function allocateQuoteNumberIfMissing(Quote $quote): void
    {
        if ($quote->quote_number !== null && $quote->quote_number !== '') {
            return;
        }

        $allocator = $this->allocator ?? new DocumentNumberAllocatorService();
        $quote->quote_number = $allocator->allocate(
            $quote->apps_id,
            $quote->companies_id,
            DocumentTypeEnum::QUOTE,
            defaultPrefix: '',
        );
    }
}
