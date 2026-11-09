<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Actions;

use Baka\Contracts\BillableInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Scribe\Delivery\Actions\SendDocumentEngagementAction;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentDelivery;
use Kanvas\Scribe\Delivery\Support\QuoteSignatureRule;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Services\QuoteStateMachineService;
use Kanvas\Users\Models\Users;

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
 * With a `deliverTo` person it then actually sends it (plan 5.4): a `view-quote` engagement, or
 * `sign-sow` when the quote carries a SOW (the customer must sign to accept), with the quote PDF and
 * the SOW attached. Without one it only flips the status, which is what the UI's "mark as sent" does.
 * A SENT quote can be delivered again; the open engagement and its link are reused.
 *
 * No JE — quotes are pre-economic-event.
 *
 * @see plan §11.1 — quote → revision → accept → convert worked example
 */
class SendQuoteAction
{
    public ?DocumentDelivery $delivery = null;

    public function __construct(
        public readonly Quote $quote,
        public readonly BillableInterface $billable,
        public readonly ?UserInterface $user = null,
        protected readonly QuoteStateMachineService $stateMachine = new QuoteStateMachineService(),
        protected readonly ?DocumentNumberAllocatorService $allocator = null,
        protected readonly ?People $deliverTo = null,
        protected readonly ?LeadCommunicationChannelEnum $channel = null,
        protected readonly ?Agent $agent = null,
        protected readonly ?string $body = null,
    ) {
    }

    public function execute(): Quote
    {
        $this->stateMachine->assertTransition($this->quote, QuoteStatusEnum::SENT);

        $send = $this->deliverTo !== null ? $this->sendAction() : null;
        // Checked before the status flips, so a quote nobody can receive is not left SENT.
        $send?->assertDeliverable();

        $quote = $this->quote->status === QuoteStatusEnum::SENT ? $this->quote : $this->markSent();

        if ($send !== null) {
            $this->delivery = $send->execute();

            $quote->emitLedgerEvent('scribe.quote.sent', payload: [
                'quote_number' => $quote->quote_number,
                'engagement_uuid' => $this->delivery->engagement->uuid,
                'action' => $this->delivery->engagement->slug,
                'channel' => $this->delivery->target->channel->value,
                'people_id' => $this->deliverTo->getId(),
            ]);
        }

        return $quote;
    }

    private function sendAction(): SendDocumentEngagementAction
    {
        $actor = $this->user instanceof Users ? $this->user : $this->quote->company->getAiAgentUserOrFail();

        return new SendDocumentEngagementAction(
            document: $this->quote,
            people: $this->deliverTo,
            user: $actor,
            action: QuoteSignatureRule::actionFor($this->quote),
            channel: $this->channel,
            agent: $this->agent,
            body: $this->body,
        );
    }

    private function markSent(): Quote
    {
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
        $quote->billable_email = $this->contactEmail($quote) ?? $billable->getBillingEmail();
        $quote->billing_address_snapshot = $billable->getBillingAddressArray();
    }

    /**
     * A quote addressed to a named human is delivered to that human, so their address outranks the
     * organization's generic billing mailbox — the org is still the legal customer either way.
     */
    private function contactEmail(Quote $quote): ?string
    {
        $email = $quote->contact?->getEmails()->first()?->value;

        return Str::trimToNull((string) $email);
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
        if (Str::trimToNull($quote->quote_number) !== null) {
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
