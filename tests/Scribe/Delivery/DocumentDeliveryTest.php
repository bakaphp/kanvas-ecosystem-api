<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use App\GraphQL\Scribe\Queries\DocumentEngagementQuery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Events\EngagementReferenceCompletedEvent;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SendInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SendQuoteTool;
use Kanvas\NervousSystem\Ledger\Models\Event as LedgerEvent;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;
use Kanvas\Scribe\Delivery\Actions\RecordDocumentResponseAction;
use Kanvas\Scribe\Delivery\DataTransferObject\DunningSchedule;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalEnum;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Models\DocumentAcceptance;
use Kanvas\Scribe\Delivery\Models\DocumentSignalDispatch;
use Kanvas\Scribe\Delivery\Services\BillingContactResolverService;
use Kanvas\Scribe\Delivery\Support\DocumentActionsSetup;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Notifications\Enums\ScribeEmailTemplateEnum;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;

class DocumentDeliveryTest extends DocumentDeliveryTestCase
{
    public function test_setup_creates_every_stage_and_page_path_and_a_rerun_changes_nothing(): void
    {
        $this->artisan('scribe:setup-document-actions', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();

        $stageCount = fn (): int => Pipeline::query()
            ->where('companies_id', $this->company->getId())
            ->whereIn('slug', array_column(DocumentActionEnum::cases(), 'value'))
            ->withCount('stages')
            ->get()
            ->sum('stages_count');

        $this->assertSame(3 * 6, $stageCount());
        $config = $this->company->get(DocumentActionsSetup::PAGE_CONFIG_KEY);
        $this->assertSame('invoice', $config['view-invoice']);
        $this->assertSame('sow', $config['sign-sow']);
        $this->assertSame(DunningSchedule::DEFAULT_STEPS, $this->company->get(DunningSchedule::SETTING)['steps']);

        $this->company->set(DocumentActionsSetup::PAGE_CONFIG_KEY, [...$config, 'view-quote' => 'custom-quote']);
        $this->artisan('scribe:setup-document-actions', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();

        $this->assertSame(3 * 6, $stageCount());
        $this->assertSame('custom-quote', $this->company->get(DocumentActionsSetup::PAGE_CONFIG_KEY)['view-quote']);
    }

    public function test_an_e_cf_invoice_is_never_sent_before_dgii_accepts_it(): void
    {
        $invoice = $this->issue($this->draftInvoice($this->customer));

        try {
            $this->send($invoice);
            $this->fail('A waiting e-CF went out.');
        } catch (DocumentDeliveryException $e) {
            $this->assertSame(DocumentDeliveryException::ECF_NOT_ACCEPTED, $e->reason);
        }

        $this->assertSame(0, Engagement::query()->whereReference($invoice)->count());
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->refresh()->document_status);
        Notification::assertNothingSent();
    }

    public function test_accepted_invoice_goes_out_through_a_view_invoice_engagement_and_payment_completes_it(): void
    {
        $invoice = $this->acceptedInvoice();

        $delivery = $this->send($invoice);
        $engagement = $delivery->engagement;

        $this->assertSame(EngagementReferenceMap::SCRIBE_INVOICE, $engagement->entity_type);
        $this->assertSame($invoice->getId(), (int) $engagement->entity_id);
        $this->assertNull($engagement->leads_id);
        $this->assertSame($this->contact->getId(), (int) $engagement->people_id);
        $this->assertSame(DocumentActionEnum::VIEW_INVOICE->value, $engagement->slug);
        $this->assertSame(ActionStatusEnum::SENT->value, $engagement->stage->slug);
        $this->assertNotSame('', $delivery->link);
        $this->assertSame('ana.billing@example.com', $delivery->target->destination);
        $this->assertStringContainsString($delivery->link, (string) $delivery->message->getMessage()['content']);

        // Scribe's own template, seeded for every app; a leads template exists only for some companies.
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getTemplateName() === ScribeEmailTemplateEnum::DOCUMENT_DELIVERY->value
        );

        $invoice->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::SENT, $invoice->document_status);
        $this->assertNotNull($invoice->sent_at);
        $this->assertTrue($this->ledgerHas($invoice, 'scribe.invoice.sent'));

        $page = new DocumentEngagementQuery()->page($engagement->refresh());
        $this->assertSame('E310000000004', $page['ecf']['encf']);
        $this->assertSame('ABC123', $page['ecf']['security_code']);
        $this->assertArrayHasKey('legal_pdf', $page['files']);
        $this->assertArrayNotHasKey('provider_ref', $page['ecf']);
        $this->assertArrayNotHasKey('internal_notes', $page);

        new AllocateInvoicePaymentAction(
            invoice: $invoice,
            amountNative: (float) $invoice->balance_due_native,
            reference: 'TRX-1',
            user: static::$cachedUser,
        )->execute();

        $completed = $this->threadRow($engagement, ActionStatusEnum::COMPLETED);
        $this->assertNotNull($completed);
        Event::assertDispatched(
            EngagementReferenceCompletedEvent::class,
            fn (EngagementReferenceCompletedEvent $event): bool => $event->entityType === EngagementReferenceMap::SCRIBE_INVOICE
                && $event->entityId === $invoice->getId()
                && $event->status === ActionStatusEnum::COMPLETED->value
        );
    }

    public function test_a_resend_reuses_the_open_engagement_and_its_link(): void
    {
        $invoice = $this->acceptedInvoice();

        $first = $this->send($invoice);
        $second = $this->send($invoice->refresh());

        $this->assertSame($first->engagement->getId(), $second->engagement->getId());
        $this->assertSame($first->link, $second->link);
        $this->assertSame(1, Engagement::query()->whereReference($invoice)->count());
    }

    public function test_an_invoice_with_no_known_billing_contact_makes_the_agent_ask(): void
    {
        $nobody = $this->customer('Sin Contacto SRL');
        $invoice = $this->acceptedInvoice($nobody);

        $result = new SendInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: $invoice->getId());

        $this->assertFalse($result['sent']);
        $this->assertSame(DocumentDeliveryException::NO_CONTACT, $result['reason']);

        $named = $this->personOf($nobody, 'cxp@sincontacto.example');
        $result = new SendInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: $invoice->getId(), person_id: $named->getId());

        $this->assertTrue($result['sent']);
        $this->assertSame('cxp@sincontacto.example', $result['recipients'][0]['sent_to']);
        $this->assertTrue(new BillingContactResolverService()->isBillingContact($named));
    }

    public function test_a_quote_with_a_sow_is_signed_on_the_page_and_acceptance_starts_its_billing_schedules(): void
    {
        $quote = $this->draftQuoteWithSow();

        $action = new SendQuoteAction(
            quote: $quote,
            billable: $this->customer,
            user: static::$cachedUser,
            deliverTo: $this->contact,
        );
        $sent = $action->execute();
        $engagement = $action->delivery->engagement;

        $this->assertSame(QuoteStatusEnum::SENT, $sent->status);
        $this->assertSame(DocumentActionEnum::SIGN_SOW->value, $engagement->slug);
        $this->assertSame(EngagementReferenceMap::SCRIBE_QUOTE, $engagement->entity_type);
        $page = new DocumentEngagementQuery()->page($engagement);
        $this->assertTrue($page['requires_signature']);
        $this->assertSame('Build the app.', $page['sow']['summary']);

        new RecordDocumentResponseAction($engagement, ActionStatusEnum::OPEN)->execute();
        new RecordDocumentResponseAction($engagement, ActionStatusEnum::OPEN)->execute();
        $this->assertSame(1, $this->threadCount($engagement, ActionStatusEnum::OPEN));

        try {
            new RecordDocumentResponseAction($engagement, ActionStatusEnum::SUBMITTED)->execute();
            $this->fail('A SOW quote was accepted without a signer.');
        } catch (DocumentDeliveryException) {
        }
        $this->assertSame(QuoteStatusEnum::SENT, $quote->refresh()->status);

        try {
            new RecordDocumentResponseAction(
                engagement: $engagement,
                status: ActionStatusEnum::SUBMITTED,
                signerName: 'Jane Customer',
                signature: 'https://evil.example/sig.png',
            )->execute();
            $this->fail('A signature URL was accepted.');
        } catch (DocumentDeliveryException) {
        }

        new RecordDocumentResponseAction(
            engagement: $engagement,
            status: ActionStatusEnum::SUBMITTED,
            signerName: 'Jane Customer',
        )->execute();

        $quote->refresh();
        $this->assertSame(QuoteStatusEnum::ACCEPTED, $quote->status);
        $this->assertSame('Jane Customer', DocumentAcceptance::forQuoteRevision($quote)?->signer_name);
        $this->assertNotNull($this->threadRow($engagement, ActionStatusEnum::SUBMITTED));
        $this->assertSame(2, BillingSchedule::query()->where('source_quote_id', $quote->getId())->count());
        Event::assertDispatched(
            EngagementReferenceCompletedEvent::class,
            fn (EngagementReferenceCompletedEvent $event): bool => $event->entityType === EngagementReferenceMap::SCRIBE_QUOTE
                && $event->status === ActionStatusEnum::SUBMITTED->value
        );
    }

    public function test_declining_rejects_the_quote_with_the_reason_and_hands_the_agent_a_signal(): void
    {
        $quote = $this->draftQuote();
        $action = new SendQuoteAction(
            quote: $quote,
            billable: $this->customer,
            user: static::$cachedUser,
            deliverTo: $this->contact,
        );
        $action->execute();
        $engagement = $action->delivery->engagement;
        $this->assertSame(DocumentActionEnum::VIEW_QUOTE->value, $engagement->slug);

        try {
            new RecordDocumentResponseAction($engagement, ActionStatusEnum::DECLINED)->execute();
            $this->fail('A decline without a reason was accepted.');
        } catch (DocumentDeliveryException) {
        }

        new RecordDocumentResponseAction(
            engagement: $engagement,
            status: ActionStatusEnum::DECLINED,
            reason: 'Too expensive for this quarter',
        )->execute();

        $quote->refresh();
        $this->assertSame(QuoteStatusEnum::REJECTED, $quote->status);
        $this->assertSame('Too expensive for this quarter', $quote->lost_reason);
        $this->assertNotNull($this->threadRow($engagement, ActionStatusEnum::DECLINED));
        $this->assertTrue(DocumentSignalDispatch::query()
            ->where('signal_key', DocumentSignalDispatch::keyFor($quote, DocumentSignalEnum::QUOTE_DECLINED))
            ->exists());
        $this->assertTrue(LedgerEvent::query()
            ->where('event_type', 'scribe.quote.engagement.signal')
            ->where('source_entity_type', Quote::class)
            ->where('source_entity_id', $quote->getId())
            ->exists());
    }

    public function test_send_quote_tool_numbers_and_delivers_to_the_named_person(): void
    {
        $quote = $this->draftQuote();

        $result = new SendQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $quote->getId(), person_id: $this->contact->getId(), message: 'Aquí está la cotización.');

        $this->assertTrue($result['sent']);
        $this->assertSame(QuoteStatusEnum::SENT->value, $result['status']);
        $this->assertNotEmpty($result['quote_number']);
        $this->assertSame('ana.billing@example.com', $result['recipients'][0]['sent_to']);
        $this->assertNotEmpty($result['recipients'][0]['link']);
        Notification::assertSentOnDemand(Blank::class);
    }

    public function test_an_invoice_cannot_be_accepted_from_the_page(): void
    {
        $delivery = $this->send($this->acceptedInvoice());

        $this->expectException(DocumentDeliveryException::class);
        new RecordDocumentResponseAction($delivery->engagement, ActionStatusEnum::SUBMITTED)->execute();
    }

    public function test_the_public_page_query_and_response_mutation_work_with_the_app_key(): void
    {
        $quote = $this->draftQuote();
        $action = new SendQuoteAction(
            quote: $quote,
            billable: $this->customer,
            user: static::$cachedUser,
            deliverTo: $this->contact,
        );
        $action->execute();
        $uuid = $action->delivery->engagement->uuid;
        $headers = [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $this->kanvasApp->keys()->first()->client_secret_id];

        $page = $this->graphQL(
            'query($uuid: Mixed!) {
                engagements(where: { column: UUID, operator: EQ, value: $uuid }) {
                    data { uuid entity_type document_page reference { ... on ScribeQuote { quote_number } } }
                }
            }',
            ['uuid' => $uuid],
            [],
            $headers
        )->assertSuccessful()->json('data.engagements.data.0');

        $this->assertSame('scribe_quote', $page['entity_type']);
        $this->assertSame($quote->refresh()->quote_number, $page['document_page']['number']);
        $this->assertSame($quote->quote_number, $page['reference']['quote_number']);

        $response = $this->graphQL(
            'mutation($uuid: ID!, $input: ScribeDocumentResponseInput!) {
                respondToDocumentEngagement(uuid: $uuid, input: $input) { uuid stage { slug } }
            }',
            ['uuid' => $uuid, 'input' => ['status' => 'DECLINED', 'reason' => 'Went with another vendor']],
            [],
            $headers
        )->assertSuccessful();

        $this->assertSame('declined', $response->json('data.respondToDocumentEngagement.stage.slug'));
        $this->assertSame(QuoteStatusEnum::REJECTED, $quote->refresh()->status);
    }
}
