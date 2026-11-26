<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Baka\Support\Str as BakaStr;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Communication\Connections\Actions\ReleaseUnusedRegistrationsAction;
use Kanvas\Communication\Connections\Actions\ResolveIntegrationAppAction;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\EmailOptions;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Communication\Support\TransportManager;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Connectors\Mailgun\EmailTransport;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Connectors\Mailgun\MailgunProvisioner;
use Kanvas\Connectors\Mailgun\Webhooks\ScribePdfInboundWebhookJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use RuntimeException;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * Mailgun on the shared pipeline: the one webhook job takes its routed mail and its domain events,
 * and a reply leaves from the address the mail reached, threaded under it.
 */
final class MailgunProviderTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string DOMAIN = 'mg.kanvas.test';
    private const string OUR_ADDRESS = 'sales@mg.kanvas.test';
    private const string OURS = 'https://kanvas.test/v1/receiver/ours';

    private string $signingKey = 'mailgun-signing-test';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config([
            'communication.providers' => [MailgunHandler::class],
            'queue.default' => 'sync',
            'services.mailgun.secret' => 'key-test',
            'app.url' => 'https://kanvas.test',
        ]);

        $company = $this->currentCompany();
        $company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, $this->signingKey);
        $company->set(ConfigurationEnum::DOMAIN->value, self::DOMAIN);
        $company->set(ConfigurationEnum::FROM_ADDRESS->value, self::OUR_ADDRESS);

        LeadType::firstOrCreate(
            ['apps_id' => $this->currentApp()->getId(), 'companies_id' => $company->getId(), 'name' => 'Warm'],
            ['description' => 'Warm Lead Type', 'is_active' => true, 'uuid' => Str::uuid()]
        );
    }

    /**
     * Company settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        foreach ([ConfigurationEnum::WEBHOOK_SIGNING_KEY, ConfigurationEnum::DOMAIN, ConfigurationEnum::FROM_ADDRESS] as $key) {
            $this->currentCompany()->del($key->value);
        }

        $this->currentCompany()->del('from_email_address');

        parent::tearDown();
    }

    public function testMailgunIsRegisteredAndCarriesEmail(): void
    {
        $this->assertSame(MailgunHandler::class, new ProviderRegistry()->get('mailgun'));
        $this->assertInstanceOf(EmailTransport::class, new TransportManager()->for('mailgun', ChannelTypeEnum::EMAIL));
        $this->assertFalse(new TransportManager()->supports('mailgun', ChannelTypeEnum::SMS));
    }

    /**
     * The account is the address the company set up on its Mailgun domain; without one, its own
     * from email stands in when it is on the domain, and nothing is connected otherwise.
     */
    public function testTheAccountIsTheCompanysAddressOnItsMailgunDomain(): void
    {
        $company = $this->currentCompany();
        $integration = new IntegrationsCompany(['companies_id' => $company->getId(), 'apps_id' => $this->currentApp()->getId()]);
        $integration->setRelation('company', $company);
        $integration->setRelation('app', $this->currentApp());

        // Agents other suites left on the shared company each add their mailbox; the company's own
        // address is the default one.
        $companyAccounts = fn (): array => array_values(array_filter(
            MailgunHandler::accounts($integration),
            fn (AccountSelection $account): bool => $account->isDefault
        ));

        $accounts = $companyAccounts();

        $this->assertCount(1, $accounts);
        $this->assertSame(self::OUR_ADDRESS, $accounts[0]->key);
        $this->assertSame(['domain' => self::DOMAIN], $accounts[0]->attributes);

        $company->del(ConfigurationEnum::FROM_ADDRESS->value);
        $company->set('from_email_address', 'Hello@MG.kanvas.test');

        $this->assertSame('hello@mg.kanvas.test', $companyAccounts()[0]->key);

        $company->set('from_email_address', 'hello@elsewhere.test');

        $this->assertSame([], $companyAccounts());
    }

    public function testBothPayloadShapesAreCheckedAgainstTheSigningKey(): void
    {
        $receiver = $this->receiver();

        $this->assertTrue(ProcessCommunicationWebhookJob::authenticateRequest($this->routedMailRequest($receiver, $this->inboundPayload('ana@customer.test', 'Hola')), $receiver));
        $this->assertTrue(ProcessCommunicationWebhookJob::authenticateRequest($this->eventRequest($receiver, $this->eventPayload('delivered', 'id-1')), $receiver));
        $this->assertFalse(ProcessCommunicationWebhookJob::authenticateRequest(
            $this->routedMailRequest($receiver, $this->inboundPayload('ana@customer.test', 'Hola'), key: 'someone-else'),
            $receiver
        ));
    }

    public function testAnInboundMailIsFiledOnTheConnectionAndTheSendersConversation(): void
    {
        $connection = $this->mailgunConnection();

        $result = $this->deliver($this->inboundPayload('Ana Pérez <ana@customer.test>', 'Is it still available?', 'ana@customer.test'));

        $message = Message::query()->findOrFail($result[0]['message_id']);
        $channel = Channel::query()->findOrFail($result[0]['channel_id']);
        $person = $message->entity(People::class);

        $this->assertSame('Is it still available?', $message->message['content']);
        $this->assertSame('mailgun-email', $message->messageType->verb);
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
        $this->assertSame('email', $message->message['communication']['channel']);
        $this->assertSame('ana@customer.test', $message->message['communication']['thread']);
        $this->assertSame(self::OUR_ADDRESS, $message->message['raw_data']['recipient']);
        $this->assertSame('Availability', $message->message['raw_data']['subject']);
        $this->assertSame('email-' . BakaStr::sanitizeEmail('ana@customer.test'), $channel->slug);
        $this->assertInstanceOf(People::class, $person);
        $this->assertSame('Ana', $person->firstname);
        $this->assertSame('Pérez', $person->lastname);
        $this->assertSame('ana@customer.test', $person->contacts()->where('contacts_types_id', ContactTypeEnum::EMAIL->value)->value('value'));
        $this->assertInstanceOf(Lead::class, $message->entity(Lead::class));
    }

    /**
     * A row saved before integrations recorded their app has no Mailgun account to read a domain
     * from, so it names no accounts and waits for an operator. `communication:setup --all-apps` asks
     * every such row before it sweeps any app: a throw here stops the sweep for all of them.
     */
    public function testALegacyRowWithoutAnAppNamesNoAccounts(): void
    {
        $this->agentWithMailbox('sofia@' . self::DOMAIN);
        $integration = new IntegrationsCompany(['companies_id' => $this->currentCompany()->getId()]);
        $integration->setRelation('company', $this->currentCompany());
        $integration->setRelation('app', null);
        $integration->setRelation('integration', new Integrations(['handler' => MailgunHandler::class]));

        $this->assertSame([], MailgunHandler::accounts($integration));
        $this->assertSame(
            ['outcome' => ResolveIntegrationAppAction::UNRESOLVED, 'apps' => []],
            new ResolveIntegrationAppAction($integration)->execute(),
        );
    }

    /**
     * Each agent mailbox is its own account, carrying the agent that answers it.
     */
    public function testAnAgentMailboxIsAnAccountOwnedByItsAgent(): void
    {
        $agent = $this->agentWithMailbox('sofia@' . self::DOMAIN);
        $integration = new IntegrationsCompany(['companies_id' => $this->currentCompany()->getId(), 'apps_id' => $this->currentApp()->getId()]);
        $integration->setRelation('company', $this->currentCompany());
        $integration->setRelation('app', $this->currentApp());

        $account = collect(MailgunHandler::accounts($integration))->firstWhere('key', 'sofia@' . self::DOMAIN);

        $this->assertNotNull($account);
        $this->assertSame($agent->getId(), $account->attributes['agent_id']);
        $this->assertFalse($account->isDefault);
    }

    public function testTheAccountingInboxIsNeverAnAccount(): void
    {
        $this->agentWithMailbox('accounting@' . self::DOMAIN);
        ReceiverWebhook::factory()
            ->app($this->currentApp()->getId())
            ->user(auth()->user()->getId())
            ->company($this->currentCompany()->getId())
            ->create([
                'action_id' => WorkflowAction::firstOrCreate(
                    ['model_name' => ScribePdfInboundWebhookJob::class],
                    ['name' => 'ScribePdfInboundWebhookJob'],
                )->getId(),
                'configuration' => ['scribe_pdf_inbox' => true, 'inbox_address' => 'accounting@' . self::DOMAIN],
            ]);
        $integration = new IntegrationsCompany(['companies_id' => $this->currentCompany()->getId(), 'apps_id' => $this->currentApp()->getId()]);
        $integration->setRelation('company', $this->currentCompany());
        $integration->setRelation('app', $this->currentApp());

        $this->assertNull(
            collect(MailgunHandler::accounts($integration))->firstWhere('key', 'accounting@' . self::DOMAIN)
        );
    }

    /**
     * Mail to an agent's own address is not a lead form: a stranger is turned away from a mailbox
     * that only takes known senders, and one on file keeps a thread of their own with that agent.
     */
    public function testAnAgentMailboxTurnsAStrangerAwayAndKeepsItsOwnThreadWithAKnownSender(): void
    {
        $agent = $this->agentWithMailbox('sofia@' . self::DOMAIN);
        $connection = $this->connection('mailgun', 'sofia@' . self::DOMAIN, ['config' => ['domain' => self::DOMAIN, 'agent_id' => $agent->getId()]]);
        $this->claim($connection);
        $known = $this->personWithEmail('ana@customer.test');

        $stranger = $this->deliver(['recipient' => 'sofia@' . self::DOMAIN] + $this->inboundPayload('nobody@nowhere.test', 'Hello'));
        $onFile = $this->deliver(['recipient' => 'sofia@' . self::DOMAIN] + $this->inboundPayload('ana@customer.test', 'Hello Sofia'));

        $this->assertSame('unknown_sender', $stranger[0]['reason']);

        $message = Message::query()->findOrFail($onFile[0]['message_id']);

        $this->assertSame($known->getId(), $message->entity(People::class)?->getId());
        $this->assertNull($message->entity(Lead::class));
        $this->assertSame(
            'agent-' . $agent->getId() . '-email-' . BakaStr::sanitizeEmail('ana@customer.test'),
            Channel::query()->findOrFail($onFile[0]['channel_id'])->slug
        );
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
    }

    public function testAnAutoReplyIsNotFiled(): void
    {
        $this->mailgunConnection();

        $result = $this->deliver($this->inboundPayload('robot@customer.test', 'Out of office') + ['Auto-Submitted' => 'auto-replied']);

        $this->assertSame([], $result);
    }

    public function testADomainEventUpdatesTheLedgerDelivery(): void
    {
        $delivery = $this->acceptedMailgunDelivery('20261110.1@mg.kanvas.test');

        $this->processCall($this->eventPayload('delivered', '<20261110.1@mg.kanvas.test>'));
        $this->processCall($this->eventPayload('opened', '<20261110.1@mg.kanvas.test>'));

        $this->assertSame(DeliveryStatusEnum::READ, $delivery->fresh()->status);
        $this->assertSame(2, $delivery->events()->count());
    }

    public function testAPermanentFailureEndsTheDelivery(): void
    {
        $delivery = $this->acceptedMailgunDelivery('20261110.2@mg.kanvas.test');

        $result = $this->processCall($this->eventPayload('failed', '<20261110.2@mg.kanvas.test>', ['severity' => 'permanent', 'delivery-status' => ['code' => 550, 'message' => 'No such user']]));

        $this->assertSame('Mailgun delivery event recorded', $result['message']);
        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->fresh()->status);
        $this->assertSame('550', $delivery->fresh()->error_code);
        $this->assertNotNull($delivery->fresh()->events_closed_at);
    }

    public function testAReplyLeavesFromTheAddressTheMailReachedThreadedUnderIt(): void
    {
        $this->mailgunConnection();
        Http::fake(['api.mailgun.net/v3/' . self::DOMAIN . '/messages' => Http::response(['id' => '<20261110.9@mg.kanvas.test>', 'message' => 'Queued'])]);

        $inbound = Message::query()->findOrFail(
            $this->deliver($this->inboundPayload('ana@customer.test', 'Is it still available?'))[0]['message_id']
        );

        $delivery = $this->communication()->reply($inbound, 'Yes, come by today.', sync: true)->delivery(ChannelTypeEnum::EMAIL);

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame('20261110.9@mg.kanvas.test', $delivery->external_id);
        Http::assertSent(function (HttpRequest $request): bool {
            $body = $request->body();

            return str_contains($body, 'ana@customer.test')
                && str_contains($body, self::OUR_ADDRESS)
                && str_contains($body, 'Re: Availability')
                && str_contains($body, '<inbound-1@customer.test>');
        });
    }

    /**
     * What only an email carries reaches Mailgun: copies, open tracking with who it is about, and
     * the mailbox as Reply-To so the answer comes back to the agent.
     */
    public function testAnEmailFromAnAgentMailboxCarriesItsCopiesTrackingAndReplyTo(): void
    {
        $mailbox = 'sofia@' . self::DOMAIN;
        $agent = $this->agentWithMailbox($mailbox);
        $this->connection('mailgun', $mailbox, ['config' => ['domain' => self::DOMAIN, 'agent_id' => $agent->getId(), Connection::PERSONAL => true]]);
        Http::fake(['api.mailgun.net/v3/' . self::DOMAIN . '/messages' => Http::response(['id' => '<20261113.1@mg.kanvas.test>', 'message' => 'Queued'])]);
        $person = $this->personWithEmail('ana@customer.test');

        $delivery = $this->communication()
            ->send(
                $person,
                Content::text('Here is your **quote**.')
                    ->withSubject('Your quote')
                    ->withEmail(new EmailOptions(cc: ['manager@customer.test'], leadId: 42, trackOpens: true)),
                channels: ['email'],
                from: Sender::agent($agent),
                sync: true
            )
            ->delivery(ChannelTypeEnum::EMAIL);

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        Http::assertSent(function (HttpRequest $request) use ($mailbox, $person): bool {
            $body = $request->body();

            return str_contains($body, 'name="cc"')
                && str_contains($body, 'manager@customer.test')
                && str_contains($body, 'name="h:Reply-To"')
                && str_contains($body, $mailbox)
                && str_contains($body, 'name="o:tracking-opens"')
                && str_contains($body, 'name="v:people_id"')
                && str_contains($body, (string) $person->getId())
                && str_contains($body, 'name="v:lead_id"')
                && str_contains($body, '<strong>quote</strong>');
        });
    }

    public function testARefusalFromMailgunFailsTheDelivery(): void
    {
        $this->defaultEmailConnection();
        Http::fake(['api.mailgun.net/*' => Http::response(['message' => "'to' parameter is not a valid address"], 400)]);

        $delivery = $this->communication()
            ->send(
                $this->personWithEmail('ana@customer.test'),
                'Hello',
                channels: ['email'],
                sync: true
            )
            ->delivery(ChannelTypeEnum::EMAIL);

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->status);
        $this->assertSame('rejected_by_provider', $delivery->error_code);
    }

    public function testTheAddressGetsARouteAndTheDomainItsEventsReadBack(): void
    {
        $vendor = $this->fakeMailgun();
        $connection = $this->connection('mailgun', self::OUR_ADDRESS, ['config' => ['domain' => self::DOMAIN]]);

        $registration = new MailgunProvisioner()->registerInbound($connection, self::OURS);

        $this->assertSame(MailgunProvisioner::ROUTE, $registration->owned[0]->type);
        $this->assertSame(self::OURS, $registration->owned[0]->expectedValue);
        $this->assertSame('forward("' . self::OURS . '")', $vendor->routes['r-1']['actions'][0]);
        $this->assertSame(self::OURS, $registration->shared?->registered_value);
        $this->assertSame(array_fill_keys(MailgunProvisioner::EVENTS, self::OURS), $vendor->webhooks);
    }

    /**
     * Mailgun keeps one events URL per domain. A second company on the domain gets its own route
     * and leaves the events with the receiver already serving the domain.
     */
    public function testASecondCompanyOnTheDomainLeavesTheEventsWithTheReceiverServingIt(): void
    {
        $vendor = $this->fakeMailgun();
        $first = $this->receiver();
        $second = new CommunicationReceivers()->ensure($this->currentApp(), $this->otherCompany(), 'mailgun');
        $provisioner = new MailgunProvisioner();

        $provisioner->registerInbound(
            $this->connection('mailgun', self::OUR_ADDRESS, ['config' => ['domain' => self::DOMAIN]]),
            $first->getUrl()
        );
        $registration = $provisioner->registerInbound(
            $this->connection('mailgun', 'ventas@' . self::DOMAIN, ['config' => ['domain' => self::DOMAIN]]),
            $second->getUrl()
        );

        $this->assertSame($second->getUrl(), $registration->owned[0]->expectedValue);
        $this->assertSame($first->getUrl(), $registration->shared?->registered_value);
        $this->assertSame(array_fill_keys(MailgunProvisioner::EVENTS, $first->getUrl()), $vendor->webhooks);
        $this->assertSame(2, $registration->shared->liveDependents()->count());
    }

    public function testTheEventsMoveOnceTheReceiverThatHeldThemIsOff(): void
    {
        $vendor = $this->fakeMailgun();
        $first = $this->receiver();
        $second = new CommunicationReceivers()->ensure($this->currentApp(), $this->otherCompany(), 'mailgun');
        $provisioner = new MailgunProvisioner();
        $remaining = $this->connection('mailgun', 'ventas@' . self::DOMAIN, ['config' => ['domain' => self::DOMAIN]]);

        $provisioner->registerInbound(
            $this->connection('mailgun', self::OUR_ADDRESS, ['config' => ['domain' => self::DOMAIN]]),
            $first->getUrl()
        );
        $provisioner->registerInbound($remaining, $second->getUrl());
        $first->forceFill(['is_active' => false])->saveOrFail();

        $registration = $provisioner->registerInbound($remaining, $second->getUrl());

        $this->assertSame($second->getUrl(), $registration->shared?->registered_value);
        $this->assertSame(array_fill_keys(MailgunProvisioner::EVENTS, $second->getUrl()), $vendor->webhooks);
    }

    public function testACompanyStillOnTheDomainTakesTheEventsOverAsSoonAsTheirReceiverGoesOff(): void
    {
        $this->fakeMailgun();
        $first = $this->receiver();
        $second = new CommunicationReceivers()->ensure($this->currentApp(), $this->otherCompany(), 'mailgun');
        $provisioner = new MailgunProvisioner();
        $leaving = $this->connection('mailgun', self::OUR_ADDRESS, ['config' => ['domain' => self::DOMAIN]]);
        $remaining = $this->connection('mailgun', 'ventas@' . self::DOMAIN, ['config' => ['domain' => self::DOMAIN]]);
        $remaining->forceFill(['companies_id' => $second->companies_id])->saveOrFail();

        $provisioner->registerInbound($leaving, $first->getUrl());
        $provisioner->registerInbound($remaining, $second->getUrl());
        $leaving->forceFill(['setup_status' => SetupStatusEnum::DISCONNECTED])->saveOrFail();

        new ReleaseUnusedRegistrationsAction($this->currentApp())->execute();

        $this->assertFalse((bool) $first->fresh()->is_active);
        Queue::assertPushed(
            EnsureCommunicationSetupJob::class,
            fn (EnsureCommunicationSetupJob $job): bool => $job->integrationCompany->getId() === $remaining->integrations_company_id
        );
    }

    public function testARouteForwardingSomewhereElseIsBlockedNotReplaced(): void
    {
        $vendor = $this->fakeMailgun();
        $vendor->routes['r-0'] = ['id' => 'r-0', 'expression' => 'match_recipient("' . self::OUR_ADDRESS . '")', 'actions' => ['forward("https://their-crm.example.com/mail")', 'stop()']];
        $connection = $this->connection('mailgun', self::OUR_ADDRESS, ['config' => ['domain' => self::DOMAIN]]);

        try {
            new MailgunProvisioner()->registerInbound($connection, self::OURS);
            $this->fail('A foreign route was replaced.');
        } catch (SetupBlockedException $blocked) {
            $this->assertSame('foreign_registration', $blocked->reason);
        }

        $this->assertSame('forward("https://their-crm.example.com/mail")', $vendor->routes['r-0']['actions'][0]);
    }

    public function testCleanupLeavesARouteANewOwnerTookAlone(): void
    {
        $vendor = $this->fakeMailgun();
        $vendor->routes['r-7'] = ['id' => 'r-7', 'expression' => 'match_recipient("' . self::OUR_ADDRESS . '")', 'actions' => ['forward("https://kanvas.test/v1/receiver/new-owner")', 'stop()']];
        $connection = $this->connection('mailgun', self::OUR_ADDRESS);

        $task = CleanupTask::query()->create([
            'apps_id' => $connection->apps_id,
            'companies_id' => $connection->companies_id,
            'connection_id' => $connection->getId(),
            'resource_type' => MailgunProvisioner::ROUTE,
            'resource_key' => $connection->account_key,
            'external_id' => 'r-7',
            'expected_value' => self::OURS,
        ]);

        $this->assertSame(CleanupTaskStatusEnum::SUPERSEDED, new MailgunProvisioner()->cleanup($task));
        $this->assertArrayHasKey('r-7', $vendor->routes);
    }

    public function testCleanupDoesNotFinishWhenMailgunRefusesToDeleteTheRoute(): void
    {
        Http::fake(fn (HttpRequest $request) => $request->method() === 'DELETE'
            ? Http::response(['message' => 'Unavailable'], 503)
            : Http::response(['route' => ['actions' => ['forward("' . self::OURS . '")']]]));
        $connection = $this->connection('mailgun', self::OUR_ADDRESS);
        $task = new CleanupTask([
            'resource_type' => MailgunProvisioner::ROUTE,
            'external_id' => 'r-retry',
            'expected_value' => self::OURS,
        ]);
        $task->setRelation('connection', $connection);

        $this->expectException(RuntimeException::class);

        new MailgunProvisioner()->cleanup($task);
    }

    public function testSharedCleanupDoesNotFinishWhenMailgunRefusesToDeleteAWebhook(): void
    {
        $vendor = $this->fakeMailgun();
        $registration = new MailgunProvisioner()->registerInbound($this->connection('mailgun', self::OUR_ADDRESS), self::OURS)->shared;
        $vendor->failMethod = 'DELETE';

        $this->expectException(RuntimeException::class);

        new MailgunProvisioner()->releaseShared($registration);
    }

    public function testSharedCleanupRetriesWhenMailgunCannotReadTheWebhook(): void
    {
        $vendor = $this->fakeMailgun();
        $registration = new MailgunProvisioner()->registerInbound($this->connection('mailgun', self::OUR_ADDRESS), self::OURS)->shared;
        $vendor->failMethod = 'GET';

        $this->expectException(ValidationException::class);

        new MailgunProvisioner()->releaseShared($registration);
    }

    private function agentWithMailbox(string $address): Agent
    {
        $agent = Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create(['name' => 'Sofia']);
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $address);

        return $agent;
    }

    private function mailgunConnection(): Connection
    {
        $connection = $this->defaultEmailConnection();
        $this->claim($connection);

        return $connection;
    }

    private function defaultEmailConnection(): Connection
    {
        $connection = $this->connection('mailgun', self::OUR_ADDRESS, ['config' => ['domain' => self::DOMAIN]]);

        ChannelDefault::updateOrCreate(
            ['apps_id' => $connection->apps_id, 'companies_id' => $connection->companies_id, 'channel' => 'email'],
            ['connection_id' => $connection->getId()]
        );

        return $connection;
    }

    private function acceptedMailgunDelivery(string $messageId): Delivery
    {
        $this->defaultEmailConnection();

        $delivery = $this->communication()
            ->send($this->personWithEmail('ana@customer.test'), 'Hello', channels: ['email'])
            ->delivery(ChannelTypeEnum::EMAIL);

        $delivery->forceFill([
            'status' => DeliveryStatusEnum::ACCEPTED,
            'external_id' => $messageId,
            'accepted_at' => now(),
        ])->saveOrFail();

        return $delivery;
    }

    private function personWithEmail(string $email): People
    {
        $people = People::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create();

        $people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => $email,
            'weight' => 0,
        ]);

        return $people;
    }

    private function receiver(): ReceiverWebhook
    {
        return new CommunicationReceivers()->ensure($this->currentApp(), $this->currentCompany(), 'mailgun');
    }

    /**
     * @return array{token: string, timestamp: string, signature: string}
     */
    private function signature(?string $key = null): array
    {
        $timestamp = (string) time();
        $token = Str::random(50);

        return [
            'token' => $token,
            'timestamp' => $timestamp,
            'signature' => hash_hmac('sha256', $timestamp . $token, $key ?? $this->signingKey),
        ];
    }

    private function routedMailRequest(ReceiverWebhook $receiver, array $payload, ?string $key = null): Request
    {
        return Request::create($receiver->getUrl(), 'POST', $payload + $this->signature($key));
    }

    private function eventRequest(ReceiverWebhook $receiver, array $payload): Request
    {
        $body = json_encode(['signature' => $this->signature()] + $payload);

        return Request::create(
            $receiver->getUrl(),
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body
        );
    }

    private function deliver(array $payload): array
    {
        $receiver = $this->receiver();
        $call = new ProcessWebhookAttemptAction($receiver, $this->routedMailRequest($receiver, $payload))->execute();

        return new ProcessCommunicationWebhookJob($call)->handle();
    }

    /**
     * Straight to the job, the way a call already authenticated runs.
     */
    private function processCall(array $payload): array
    {
        $receiver = $this->receiver();

        return new ProcessCommunicationWebhookJob(ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => $receiver->getUrl(),
            'headers' => [],
            'payload' => $payload,
        ]))->handle();
    }

    private function inboundPayload(string $from, string $text, ?string $sender = null): array
    {
        return [
            'sender' => $sender ?? $from,
            'from' => $from,
            'recipient' => self::OUR_ADDRESS,
            'subject' => 'Availability',
            'body-plain' => $text . "\n\n> older quoted text",
            'stripped-text' => $text,
            'Message-Id' => '<inbound-1@customer.test>',
            'attachment-count' => '0',
        ];
    }

    private function eventPayload(string $event, string $messageId, array $extra = []): array
    {
        return [
            'event-data' => [
                'id' => Str::random(22),
                'event' => $event,
                'timestamp' => microtime(true),
                'recipient' => 'ana@customer.test',
                'message' => ['headers' => ['message-id' => trim($messageId, '<>')]],
            ] + $extra,
        ];
    }

    /**
     * Mailgun's routes and domain webhooks, held in memory and answered by the HTTP fake.
     */
    private function fakeMailgun(): object
    {
        $vendor = new class () {
            /** @var array<string, array<string, mixed>> */
            public array $routes = [];

            /** @var array<string, string> */
            public array $webhooks = [];

            public ?string $failMethod = null;
        };

        Http::fake(function (HttpRequest $request) use ($vendor) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();

            if ($method === $vendor->failMethod) {
                return Http::response(['message' => 'Unavailable'], 503);
            }

            if ($path === '/v3/routes' && $method === 'GET') {
                return Http::response(['items' => array_values($vendor->routes)]);
            }

            if ($path === '/v3/routes' && $method === 'POST') {
                $id = 'r-' . (count($vendor->routes) + 1);
                $vendor->routes[$id] = ['id' => $id] + $this->routeFromParts($request);

                return Http::response(['route' => $vendor->routes[$id]]);
            }

            if (preg_match('#^/v3/routes/(.+)$#', (string) $path, $matches) === 1) {
                $id = urldecode($matches[1]);

                if ($method === 'PUT') {
                    $vendor->routes[$id] = ['id' => $id] + $this->routeFromParts($request);
                }

                return isset($vendor->routes[$id]) ? Http::response(['route' => $vendor->routes[$id]]) : Http::response([], 404);
            }

            if (preg_match('#^/v3/domains/[^/]+/webhooks/(.+)$#', (string) $path, $matches) === 1) {
                $event = urldecode($matches[1]);

                if ($method === 'PUT') {
                    $vendor->webhooks[$event] = $this->part($request, 'url');
                }

                return isset($vendor->webhooks[$event])
                    ? Http::response(['webhook' => ['urls' => [$vendor->webhooks[$event]]]])
                    : Http::response([], 404);
            }

            if (preg_match('#^/v3/domains/[^/]+/webhooks$#', (string) $path) === 1 && $method === 'POST') {
                $vendor->webhooks[$this->part($request, 'id')] = $this->part($request, 'url');

                return Http::response(['webhook' => ['urls' => [$this->part($request, 'url')]]]);
            }

            return Http::response([], 404);
        });

        return $vendor;
    }

    private function routeFromParts(HttpRequest $request): array
    {
        $actions = [];

        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === 'action') {
                $actions[] = $part['contents'];
            }
        }

        return ['expression' => $this->part($request, 'expression'), 'actions' => $actions];
    }

    private function part(HttpRequest $request, string $name): string
    {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === $name) {
                return (string) $part['contents'];
            }
        }

        return '';
    }
}
