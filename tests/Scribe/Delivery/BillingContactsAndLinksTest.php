<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GetDocumentLinkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SendInvoiceTool;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpInvoiceAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\AgentFollowUpResult;
use Kanvas\Intelligence\FollowUp\Services\InvoiceDunningComposer;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Services\BillingContactResolverService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;

/**
 * Billing contacts are the `billing-contact` people tag (several per customer), and get_document_link
 * hands out the same engagement link a send would, without sending anything.
 */
class BillingContactsAndLinksTest extends DocumentDeliveryTestCase
{
    private BillingContactResolverService $resolver;

    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        $this->resolver = new BillingContactResolverService();
    }

    public function test_the_resolver_prefers_named_then_quote_contact_then_every_tagged_contact_then_the_billing_email(): void
    {
        $invoice = $this->acceptedInvoice();

        $this->assertSame([$this->contact->getId()], $this->ids($this->resolver->resolve($invoice)));

        $first = $this->taggedContact('first@example.com');
        $second = $this->taggedContact('second@example.com');
        $this->assertSame([$first->getId(), $second->getId()], $this->ids($this->resolver->resolve($invoice)));

        $named = $this->unlinkedPerson('named@example.com');
        $this->assertSame([$named->getId()], $this->ids($this->resolver->resolve($invoice, $named)));

        $quote = $this->draftQuote();
        $quote->contact_people_id = $this->contact->getId();
        $quote->save();
        $this->assertSame([$this->contact->getId()], $this->ids($this->resolver->resolve($quote->refresh())));
    }

    public function test_a_named_person_becomes_a_tagged_billing_contact_linked_to_the_customer(): void
    {
        $invoice = $this->acceptedInvoice();
        $named = $this->unlinkedPerson('cxp@example.com');

        $this->resolver->resolve($invoice, $named);

        $this->assertTrue($this->resolver->isBillingContact($named));
        $this->assertTrue($this->isLinked($this->customer, $named));
        $this->assertContains($named->getId(), $this->ids($this->resolver->resolve($invoice)));
    }

    public function test_sending_without_a_person_reaches_every_billing_contact_with_its_own_engagement(): void
    {
        $invoice = $this->acceptedInvoice();
        $first = $this->taggedContact('first@example.com');
        $second = $this->taggedContact('second@example.com');

        $result = $this->sendTool()->__invoke(invoice_id: $invoice->getId());

        $this->assertTrue($result['sent']);
        $this->assertSame([$first->getId(), $second->getId()], array_column($result['recipients'], 'person_id'));
        $this->assertSame(['first@example.com', 'second@example.com'], array_column($result['recipients'], 'sent_to'));
        $this->assertCount(2, array_unique(array_column($result['recipients'], 'link')));
        $this->assertSame(2, Engagement::query()->whereReference($invoice)->count());
        Notification::assertSentOnDemandTimes(Blank::class, 2);
        $this->assertSame(InvoiceDocumentStatusEnum::SENT, $invoice->refresh()->document_status);
    }

    public function test_dunning_reminds_every_contact_that_holds_the_invoice_and_spends_the_step_once(): void
    {
        $invoice = $this->acceptedInvoice();
        $this->taggedContact('first@example.com');
        $this->taggedContact('second@example.com');
        $this->sendTool()->__invoke(invoice_id: $invoice->getId());

        app()->instance(InvoiceDunningComposer::class, new class () extends InvoiceDunningComposer {
            public function compose(
                Invoice $invoice,
                People $people,
                Agent $agent,
                int $step,
                ?Carbon $openedAt
            ): AgentFollowUpResult {
                return new AgentFollowUpResult(shouldRespond: true, advanceStage: false, message: 'Recordatorio.');
            }
        });

        Carbon::setTestNow('2026-07-12 12:00:00');
        $outcome = new FollowUpInvoiceAction(invoice: $invoice->refresh(), agent: new Agent())->execute();

        $this->assertTrue($outcome->wasSent());
        $this->assertSame('dunning:-3', $invoice->refresh()->getFollowUpLastTemplate());
        Notification::assertSentOnDemandTimes(Blank::class, 4);
        $this->assertSame(2, Engagement::query()->whereReference($invoice)->count());
    }

    public function test_get_document_link_sends_nothing_and_a_later_send_reuses_the_link(): void
    {
        $invoice = $this->acceptedInvoice();

        $result = $this->linkTool()->__invoke(document_type: 'invoice', document_id: $invoice->getId());

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['links']);
        $link = $result['links'][0];
        $this->assertSame($this->contact->getId(), $link['person_id']);
        $this->assertSame(DocumentActionEnum::VIEW_INVOICE->value, $link['action']);
        $this->assertNotEmpty($link['link']);
        Notification::assertNothingSent();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->refresh()->document_status);

        $sent = $this->sendTool()->__invoke(invoice_id: $invoice->getId());

        $this->assertSame($link['engagement_uuid'], $sent['recipients'][0]['engagement_uuid']);
        $this->assertSame($link['link'], $sent['recipients'][0]['link']);
        $this->assertSame(1, Engagement::query()->whereReference($invoice)->count());
    }

    public function test_get_document_link_refuses_an_e_cf_invoice_dgii_has_not_accepted(): void
    {
        $invoice = $this->issue($this->draftInvoice($this->customer));

        $result = $this->linkTool()->__invoke(document_type: 'invoice', document_id: $invoice->getId());

        $this->assertFalse($result['success']);
        $this->assertSame(DocumentDeliveryException::ECF_NOT_ACCEPTED, $result['reason']);
        $this->assertSame(0, Engagement::query()->whereReference($invoice)->count());
    }

    public function test_get_document_link_on_a_quote_with_a_sow_is_the_signing_page_and_a_draft_has_none(): void
    {
        $quote = $this->draftQuoteWithSow();

        $draft = $this->linkTool()->__invoke(document_type: 'quote', document_id: $quote->getId());
        $this->assertFalse($draft['success']);
        $this->assertSame(DocumentDeliveryException::INVALID_STATUS, $draft['reason']);

        $sent = new SendQuoteAction(quote: $quote, billable: $this->customer, user: static::$cachedUser)->execute();
        $this->assertSame(QuoteStatusEnum::SENT, $sent->status);

        $result = $this->linkTool()->__invoke(document_type: 'quote', document_id: $quote->getId());

        $this->assertTrue($result['success']);
        $this->assertSame(DocumentActionEnum::SIGN_SOW->value, $result['links'][0]['action']);
        Notification::assertNothingSent();
    }

    public function test_the_migrate_command_tags_the_custom_field_contact_once_and_its_dry_run_writes_nothing(): void
    {
        $person = $this->unlinkedPerson('legacy@example.com');
        $this->customer->set('billing_contact_people_id', $person->getId());
        $options = ['--app' => $this->kanvasApp->getId(), '--company' => $this->company->getId()];

        $this->artisan('scribe:migrate-billing-contacts', [...$options, '--dry-run' => true])
            ->expectsOutputToContain('tagged + linked: 1')
            ->assertSuccessful();
        $this->assertFalse($this->resolver->isBillingContact($person));
        $this->assertFalse($this->isLinked($this->customer, $person));

        $this->artisan('scribe:migrate-billing-contacts', $options)
            ->expectsOutputToContain('tagged + linked: 1')
            ->assertSuccessful();
        $this->assertTrue($this->resolver->isBillingContact($person));
        $this->assertTrue($this->isLinked($this->customer, $person));

        $this->artisan('scribe:migrate-billing-contacts', $options)
            ->expectsOutputToContain('tagged + linked: 0, already done: 1')
            ->assertSuccessful();
        $this->assertSame($person->getId(), (int) $this->customer->get('billing_contact_people_id'));
    }

    private function sendTool(): SendInvoiceTool
    {
        return new SendInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function linkTool(): GetDocumentLinkTool
    {
        return new GetDocumentLinkTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function taggedContact(string $email): People
    {
        $person = $this->personOf($this->customer, $email);
        $this->resolver->markBillingContact($person, $this->customer);

        return $person;
    }

    private function unlinkedPerson(string $email): People
    {
        $person = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['firstname' => 'Luis', 'lastname' => 'Gómez']);
        $person->contacts()->delete();
        $person->addEmail($email);

        return $person;
    }

    private function isLinked(Organization $organization, People $person): bool
    {
        return OrganizationPeople::query()
            ->where('organizations_id', $organization->getId())
            ->where('peoples_id', $person->getId())
            ->exists();
    }

    /**
     * @param list<People> $people
     * @return list<int>
     */
    private function ids(array $people): array
    {
        return array_map(static fn (People $person): int => $person->getId(), $people);
    }
}
