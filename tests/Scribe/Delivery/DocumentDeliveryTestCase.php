<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Events\EngagementCompanyUpdateEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementCompletedEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementReferenceCompletedEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementStatusChangedEvent;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\ShortIo\Enums\ConfigurationEnum as ShortIoConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateQuoteTool;
use Kanvas\NervousSystem\Ledger\Models\Event as LedgerEvent;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentDelivery;
use Kanvas\Scribe\Delivery\DataTransferObject\DunningSchedule;
use Kanvas\Scribe\Delivery\Services\BillingContactResolverService;
use Kanvas\Scribe\Delivery\Support\DocumentActionsSetup;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\SendInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\Actions\RecordQuoteSowAction;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;
use Tests\Scribe\ElectronicInvoicing\EcfTestCase;
use Tests\Stubs\Scribe\FakeEcfProvider;

/**
 * Plan 2.7 / 5.4 / 3.1: every customer document goes out as an engagement on its own reference. A fresh
 * company with the fake e-CF provider (EcfTestCase), so the page-path and dunning settings written here
 * never land on the shared test company.
 */
abstract class DocumentDeliveryTestCase extends EcfTestCase
{
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'action_engine', 'social'];

    protected Organization $customer;
    protected People $contact;

    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        Notification::fake();
        Event::fake([
            EngagementCompletedEvent::class,
            EngagementStatusChangedEvent::class,
            EngagementCompanyUpdateEvent::class,
            EngagementReferenceCompletedEvent::class,
        ]);
        FakeEcfProvider::$pdfBase64 = null;

        new DocumentActionsSetup($this->kanvasApp, Auth::user(), $this->company)->run();
        $this->company->set(ShortIoConfigurationEnum::LINK_PAGE_BASE_URL->value, 'https://docs.kanvas.test');

        $this->customer = $this->customer();
        $this->contact = $this->personOf($this->customer, 'ana.billing@example.com');
    }

    protected function tearDown(): void
    {
        $this->company->del(DocumentActionsSetup::PAGE_CONFIG_KEY);
        $this->company->del(ShortIoConfigurationEnum::LINK_PAGE_BASE_URL->value);
        $this->company->del(DunningSchedule::SETTING);

        parent::tearDown();
    }

    protected function personOf(Organization $organization, string $email): People
    {
        $person = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['firstname' => 'Ana', 'lastname' => 'Pérez']);
        $person->contacts()->delete();
        $person->addEmail($email);
        $organization->addPeople($person);

        return $person;
    }

    protected function acceptedInvoice(?Organization $customer = null): Invoice
    {
        $invoice = $this->issue($this->draftInvoice($customer ?? $this->customer));
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();
        $invoice->refresh();
        $invoice->addFile($this->createFilesystemRow(companiesId: $this->company->getId()), ApplyFiscalAnswerAction::PDF_FIELD_NAME);

        return $invoice;
    }

    protected function issue(Invoice $draft): Invoice
    {
        /** @var Organization $customer */
        $customer = $draft->customer;

        return new IssueInvoiceAction(invoice: $draft, billable: $customer, user: static::$cachedUser)->execute();
    }

    protected function send(Invoice $invoice): DocumentDelivery
    {
        return new SendInvoiceAction(
            invoice: $invoice,
            people: new BillingContactResolverService()->resolve($invoice)[0],
            user: static::$cachedUser,
        )->execute();
    }

    protected function draftQuote(): Quote
    {
        $created = new CreateQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: $this->customer->name, lines: [
                ['description' => 'Discovery workshop', 'unit_price' => 1000.0, 'tax_amount' => 100.0],
                ['description' => 'Mobile app build', 'unit_price' => 3000.0],
            ]);

        $quote = Quote::getById($created['quote_id']);
        $quote->addFile($this->createFilesystemRow(companiesId: $this->company->getId()), DocumentPdfService::QUOTE_FIELD_NAME);

        return $quote;
    }

    protected function draftQuoteWithSow(): Quote
    {
        $quote = $this->draftQuote();

        new RecordQuoteSowAction($quote, StatementOfWork::fromInput([
            'summary' => 'Build the app.',
            'scope' => ['Discovery workshop', 'Mobile app build'],
            'out_of_scope' => [],
            'assumptions' => [],
            'deliverables' => [['name' => 'The app', 'acceptance_criteria' => 'Published.']],
            'timeline' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
            'payment_schedule' => [
                ['label' => 'Deposit', 'when' => 'on_signature', 'percent' => 50],
                ['label' => 'Balance', 'when' => 'date', 'date' => '2026-06-20', 'percent' => 50],
            ],
            'signatories' => [['name' => 'Jane Customer', 'role' => 'CEO']],
        ]), static::$cachedUser)->execute();

        return $quote->refresh();
    }

    protected function threadRow(Engagement $thread, ActionStatusEnum $status): ?Engagement
    {
        return Engagement::query()
            ->where('entity_uuid', $thread->entity_uuid)
            ->whereHas('stage', fn ($query) => $query->where('slug', $status->value))
            ->first();
    }

    protected function threadCount(Engagement $thread, ActionStatusEnum $status): int
    {
        return Engagement::query()
            ->where('entity_uuid', $thread->entity_uuid)
            ->whereHas('stage', fn ($query) => $query->where('slug', $status->value))
            ->count();
    }

    protected function ledgerHas(Invoice $invoice, string $eventType): bool
    {
        return LedgerEvent::query()
            ->where('event_type', $eventType)
            ->where('source_entity_type', Invoice::class)
            ->where('source_entity_id', $invoice->getId())
            ->exists();
    }
}
