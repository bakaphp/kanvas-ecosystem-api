<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpInvoiceAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\AgentFollowUpResult;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpOutcome;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpOutcomeKindEnum;
use Kanvas\Intelligence\FollowUp\Services\InvoiceDunningComposer;
use Kanvas\NervousSystem\Ledger\Models\Event as LedgerEvent;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Scribe\Delivery\Actions\RecordDocumentResponseAction;
use Kanvas\Scribe\Delivery\Actions\WatchDocumentEngagementsAction;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentDelivery;
use Kanvas\Scribe\Delivery\DataTransferObject\DunningSchedule;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalEnum;
use Kanvas\Scribe\Delivery\Models\DocumentSignalDispatch;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Social\Messages\Models\Message;

/**
 * Plan 3.1 (dunning on FollowUp v2) and the time-based half of §5.2 (quiet documents → AR agent).
 * The invoice is due 2026-07-15; the default steps are -3, 0, +7, +15, +30, then the owner.
 */
class InvoiceDunningTest extends DocumentDeliveryTestCase
{
    /** @var list<array{step: int, opened: ?Carbon}> */
    public array $composed = [];
    public AgentFollowUpResult $reply;

    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        $this->reply = new AgentFollowUpResult(shouldRespond: true, advanceStage: false, message: 'Hola Ana, le recordamos la factura.');
        $test = $this;
        app()->instance(InvoiceDunningComposer::class, new class ($test) extends InvoiceDunningComposer {
            public function __construct(private readonly InvoiceDunningTest $test)
            {
            }

            public function compose(
                Invoice $invoice,
                People $people,
                Agent $agent,
                int $step,
                ?Carbon $openedAt
            ): AgentFollowUpResult {
                $this->test->composed[] = ['step' => $step, 'opened' => $openedAt];

                return $this->test->reply;
            }
        });
    }

    public function test_the_cadence_skips_missed_steps_reuses_the_link_and_escalates_once_after_the_last(): void
    {
        [$invoice, $delivery] = $this->sentInvoice();

        $this->assertSame('not_due', $this->runOn($invoice, '2026-07-10')->reason);

        $outcome = $this->runOn($invoice, '2026-07-12');
        $this->assertTrue($outcome->wasSent());
        $this->assertSame('dunning:-3', $invoice->refresh()->getFollowUpLastTemplate());
        $this->assertSame(1, Engagement::query()->whereReference($invoice)->count());
        $this->assertSame([-3], array_column($this->composed, 'step'));

        $this->assertSame('not_due', $this->runOn($invoice, '2026-07-12')->reason);

        $this->assertTrue($this->runOn($invoice, '2026-08-01')->wasSent());
        $this->assertSame('dunning:+15', $invoice->refresh()->getFollowUpLastTemplate());
        $this->assertSame([-3, 15], array_column($this->composed, 'step'));

        $this->assertTrue($this->runOn($invoice, '2026-08-14')->wasSent());
        $this->assertSame('dunning:+30', $invoice->refresh()->getFollowUpLastTemplate());

        $escalated = $this->runOn($invoice, '2026-08-15');
        $this->assertSame(FollowUpOutcomeKindEnum::EXHAUSTED, $escalated->kind);
        $this->assertSame('escalated', $escalated->reason);
        $invoice->refresh();
        $this->assertTrue($this->signalRaised($invoice, DocumentSignalEnum::DUNNING_ESCALATED));

        $this->assertSame(FollowUpOutcomeKindEnum::EXHAUSTED, $this->runOn($invoice, '2026-08-20')->kind);
        $this->assertCount(3, $this->composed);
        $this->assertStringContainsString(
            $delivery->link,
            (string) Message::query()->whereKey($this->lastReminderMessageId($invoice))->first()?->getMessage()['content']
        );
    }

    public function test_the_agent_hears_that_the_customer_opened_the_invoice(): void
    {
        [$invoice, $delivery] = $this->sentInvoice();
        new RecordDocumentResponseAction($delivery->engagement, ActionStatusEnum::OPEN)->execute();

        $this->runOn($invoice, '2026-07-15');

        $this->assertSame(0, $this->composed[0]['step']);
        $this->assertNotNull($this->composed[0]['opened']);
    }

    public function test_a_customer_who_wrote_back_pauses_the_reminders(): void
    {
        [$invoice, $delivery] = $this->sentInvoice();

        Carbon::setTestNow('2026-07-11 10:00:00');
        $inbound = $delivery->message->replicate();
        $inbound->uuid = null;
        $inbound->message = [...$delivery->message->getMessage(), 'content' => 'Ya pagamos ayer', 'from_me' => false];
        $inbound->save();
        foreach ($delivery->message->channels as $channel) {
            $channel->addMessage($inbound);
        }

        $this->assertSame('customer_replied', $this->runOn($invoice, '2026-07-12')->reason);
        $this->assertSame([], $this->composed);
        $this->assertTrue($this->runOn($invoice, '2026-07-19')->wasSent());
    }

    public function test_an_agent_that_declines_skips_without_spending_the_step(): void
    {
        [$invoice] = $this->sentInvoice();
        $this->reply = new AgentFollowUpResult(shouldRespond: false, advanceStage: false, reason: 'they promised to pay Friday');

        $this->assertSame('agent: they promised to pay Friday', $this->runOn($invoice, '2026-07-12')->reason);
        $this->assertNull($invoice->refresh()->getFollowUpLastTemplate());
    }

    public function test_paid_disabled_and_agentless_invoices_send_nothing(): void
    {
        [$invoice] = $this->sentInvoice();

        $this->assertSame('no_ar_agent', $this->runOn($invoice, '2026-07-12', agent: false)->reason);

        $this->company->set(DunningSchedule::SETTING, [...DunningSchedule::defaults(), 'enabled' => false]);
        $this->assertSame('dunning_disabled', $this->runOn($invoice, '2026-07-12')->reason);
        $this->company->set(DunningSchedule::SETTING, DunningSchedule::defaults());

        Carbon::setTestNow($this->fiscalPeriodStart() . ' 12:00:00');
        new AllocateInvoicePaymentAction(
            invoice: $invoice,
            amountNative: (float) $invoice->balance_due_native,
            reference: 'TRX-2',
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::COMPLETED, $this->runOn($invoice->refresh(), '2026-07-20')->kind);
        $this->assertSame([], $this->composed);
    }

    public function test_quiet_documents_become_agent_signals_once(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $unopened = $this->sentQuote();
        $opened = $this->sentQuote();
        new RecordDocumentResponseAction($opened['engagement'], ActionStatusEnum::OPEN)->execute();

        Carbon::setTestNow('2026-06-02 12:00:00');
        $this->assertSame(0, new WatchDocumentEngagementsAction($this->kanvasApp)->execute());

        Carbon::setTestNow('2026-06-05 12:00:00');
        $this->assertSame(2, new WatchDocumentEngagementsAction($this->kanvasApp)->execute());
        $this->assertSame(0, new WatchDocumentEngagementsAction($this->kanvasApp)->execute());

        $this->assertTrue($this->signalRaised($unopened['quote'], DocumentSignalEnum::NEVER_OPENED));
        $this->assertTrue($this->signalRaised($opened['quote'], DocumentSignalEnum::QUOTE_OPENED_NOT_ANSWERED));
    }

    private function signalRaised(Invoice|Quote $document, DocumentSignalEnum $signal): bool
    {
        return DocumentSignalDispatch::query()->where('signal_key', DocumentSignalDispatch::keyFor($document, $signal))->exists();
    }

    /**
     * @return array{0: Invoice, 1: DocumentDelivery}
     */
    private function sentInvoice(): array
    {
        $invoice = $this->acceptedInvoice();
        $delivery = $this->send($invoice);
        Notification::assertSentOnDemandTimes(Blank::class, 1);

        return [$invoice->refresh(), $delivery];
    }

    /**
     * @return array{quote: Quote, engagement: Engagement}
     */
    private function sentQuote(): array
    {
        $action = new SendQuoteAction(
            quote: $this->draftQuote(),
            billable: $this->customer,
            user: static::$cachedUser,
            deliverTo: $this->contact,
        );

        return ['quote' => $action->execute(), 'engagement' => $action->delivery->engagement];
    }

    private function runOn(Invoice $invoice, string $date, bool $agent = true): FollowUpOutcome
    {
        Carbon::setTestNow($date . ' 12:00:00');

        return new FollowUpInvoiceAction(invoice: $invoice->refresh(), agent: $agent ? new Agent() : null)->execute();
    }

    private function lastReminderMessageId(Invoice $invoice): ?int
    {
        $event = LedgerEvent::query()
            ->where('event_type', 'scribe.invoice.follow_up.sent')
            ->where('source_entity_type', Invoice::class)
            ->where('source_entity_id', $invoice->getId())
            ->latest('id')
            ->first();

        return isset($event?->payload['message_id']) ? (int) $event->payload['message_id'] : null;
    }
}
