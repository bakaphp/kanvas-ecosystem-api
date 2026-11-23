<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use App\GraphQL\Connector\WhatsApp\WhatsappConnectorResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\Actions\RespondToInboundMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WaSender\Actions\RetireLegacyWhatsAppInboundAction;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Connectors\WaSender\Services\GroupMentionService;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Connectors\WaSender\WaSenderInbound;
use Kanvas\Connectors\WaSender\WaSenderProvisioner;
use Kanvas\Connectors\WaSender\WhatsAppTransport;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\Communication\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Rules\Models\Rule;
use Mockery;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Concerns\BuildsLegacyInboundFixtures;
use Tests\TestCase;
use Tests\Workflow\Support\WatchesWorkflowEvents;

/**
 * WaSender as a Communication provider: the number is a connection its agent owns, the one webhook
 * serves every WaSender event, a lead is answered by the owning agent with no rule, and a send goes
 * through the ledger.
 */
final class WaSenderProviderTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use BuildsLegacyInboundFixtures;
    use DatabaseTransactions;
    use WatchesWorkflowEvents;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private Rule $turns;

    private const string OUR_NUMBER = '+18095550100';
    private const string CUSTOMER = '18095557788';

    protected function setUp(): void
    {
        parent::setUp();

        $this->turns = $this->watchWorkflowEvent(WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value, Channel::class);
        Queue::fake();
        Notification::fake();
        config([
            'communication.providers' => [WaSenderHandler::class],
            'app.url' => 'https://kanvas.test',
        ]);

        LeadType::firstOrCreate(
            ['apps_id' => $this->currentApp()->getId(), 'companies_id' => $this->currentCompany()->getId(), 'name' => 'Warm'],
            ['description' => 'Warm Lead Type', 'is_active' => true, 'uuid' => Str::uuid()]
        );
    }

    public function testWaSenderIsRegisteredAndCarriesWhatsApp(): void
    {
        $this->assertSame(WaSenderHandler::class, new ProviderRegistry()->get('wasender'));
        $this->assertSame([ChannelTypeEnum::WHATSAPP->value => WhatsAppTransport::class], WaSenderHandler::transports());
    }

    public function testTheAccountIsTheNumberAnAgentLinkedAndThatAgentOwnsIt(): void
    {
        $company = Companies::factory()->create();
        $agent = $this->agent(company: $company);
        $agent->set(ConnectionFieldEnum::PHONE_NUMBER->value, 18095550100);
        $agent->set(ConnectionFieldEnum::SESSION_ID->value, 909);

        $accounts = WaSenderHandler::accounts($this->integrationRow($company));

        $this->assertCount(1, $accounts);
        $this->assertSame(self::OUR_NUMBER, $accounts[0]->key);
        $this->assertSame($agent->getId(), $accounts[0]->attributes['agent_id']);
        $this->assertSame(909, $accounts[0]->attributes['session_id']);
    }

    public function testTheNumberSavedOnTheIntegrationWinsAndItsDeclaredOwnerAnswers(): void
    {
        $company = Companies::factory()->create();
        $linked = $this->agent(company: $company);
        $linked->set(ConnectionFieldEnum::PHONE_NUMBER->value, '18095550999');
        $owner = $this->agent(['whatsapp_config' => ['phone_number' => self::OUR_NUMBER]], $company);

        $accounts = WaSenderHandler::accounts($this->integrationRow($company, ['phone_number' => self::OUR_NUMBER]));

        $this->assertSame(self::OUR_NUMBER, $accounts[0]->key);
        $this->assertSame($owner->getId(), $accounts[0]->attributes['agent_id']);
    }

    public function testACompanyThatLinkedNoNumberHasNoAccount(): void
    {
        $this->assertSame([], WaSenderHandler::accounts($this->integrationRow(Companies::factory()->create())));
    }

    public function testASignedReceiverTakesOnlyItsOwnSecret(): void
    {
        $receiver = $this->receiver(['webhook_secret' => 'the-session-secret']);
        $inbound = new WaSenderInbound();

        $this->assertTrue($inbound->authenticate($this->request('the-session-secret'), $receiver));
        $this->assertFalse($inbound->authenticate($this->request('someone-elses'), $receiver));
        $this->assertFalse($inbound->authenticate($this->request(null), $receiver));
    }

    public function testAReceiverThatNeverGotASecretTakesOnlyUnsignedCalls(): void
    {
        $receiver = $this->receiver();
        $inbound = new WaSenderInbound();

        $this->assertTrue($inbound->authenticate($this->request(null), $receiver));
        $this->assertFalse($inbound->authenticate($this->request('anything'), $receiver));
    }

    public function testALeadMessageIsStampedWithTheConnectionAndItsOwningAgentIsAskedToAnswer(): void
    {
        $agent = $this->agent();
        $connection = $this->connection('wasender', self::OUR_NUMBER, ['config' => ['agent_id' => $agent->getId()]]);

        $result = $this->deliver($this->directText('WAMSG-OWNED'));
        $message = Message::getById($result['result']['messages'][0]['message_id'], $this->currentApp());

        $this->assertSame(
            [
                'connection_id' => $connection->getId(),
                'channel' => 'whatsapp',
                'thread' => self::CUSTOMER,
                'activation_version' => 1,
            ],
            $message->message['communication']
        );
        $turn = $this->firedWith($this->turns)->sole();
        $this->assertTrue($turn['message']->is($message));
        $this->assertArrayNotHasKey('burst_text', $turn);
    }

    public function testANumberNobodyOwnsIsFiledAndLeftAlone(): void
    {
        $this->connection('wasender', self::OUR_NUMBER);

        $result = $this->deliver($this->directText('WAMSG-UNOWNED'));

        $this->assertNotEmpty($result['result']['messages']);
        $this->assertNoTurnIsAnswered();
    }

    public function testOurOwnMessageIsNeverAnswered(): void
    {
        $this->connection('wasender', self::OUR_NUMBER, ['config' => ['agent_id' => $this->agent()->getId()]]);

        $this->deliver($this->directText('WAMSG-OURS', fromMe: true));

        $this->assertNoTurnIsAnswered();
    }

    public function testBeforeTheNumberIsAConnectionAMessageIsFiledWithoutARoute(): void
    {
        $result = $this->deliver($this->directText('WAMSG-EARLY'));
        $message = Message::getById($result['result']['messages'][0]['message_id'], $this->currentApp());

        $this->assertArrayNotHasKey('communication', $message->message);
        $this->assertNoTurnIsAnswered();
    }

    public function testASendPutsFilesFirstThenTheTextAndIsAcceptedUnderWaSendersId(): void
    {
        $sent = [];
        $service = Mockery::mock(MessageService::class);
        $service->shouldReceive('sendImageMessage')->once()->andReturnUsing(function (string $to, string $url) use (&$sent): array {
            $sent[] = ['image', $to, $url];

            return ['data' => ['msgId' => 1]];
        });
        $service->shouldReceive('sendDocumentMessage')->once()->andReturnUsing(function (string $to, string $url, ?string $name) use (&$sent): array {
            $sent[] = ['document', $to, $name];

            return ['data' => ['msgId' => 2]];
        });
        $service->shouldReceive('sendTextMessage')->once()->andReturnUsing(function (string $to, string $text) use (&$sent): array {
            $sent[] = ['text', $to, $text];

            return ['data' => ['msgId' => 3]];
        });

        $delivery = $this->send(
            $this->transport($service),
            Content::text('Here is the quote')->withAttachments([
                'https://cdn.kanvas.test/car.jpg',
                new Attachment('https://cdn.kanvas.test/files/abc', 'quote.pdf', 'application/pdf'),
            ])
        );

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame('3', $delivery->external_id);
        $this->assertSame(
            [
                ['image', self::CUSTOMER, 'https://cdn.kanvas.test/car.jpg'],
                ['document', self::CUSTOMER, 'quote.pdf'],
                ['text', self::CUSTOMER, 'Here is the quote'],
            ],
            $sent
        );
    }

    public function testARefusalFromWaSenderFailsTheDelivery(): void
    {
        $service = Mockery::mock(MessageService::class);
        $service->shouldReceive('sendTextMessage')->andThrow(new WaSenderRefusedException('Session is not connected', 422));

        $delivery = $this->send($this->transport($service), Content::text('Hello'));

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->status);
        $this->assertSame('rejected_by_provider', $delivery->error_code);
        $this->assertSame('Session is not connected', $delivery->error);
    }

    public function testAMessageWeStartLandsInTheChannelTheCustomersAnswersArriveIn(): void
    {
        $connection = $this->connection('wasender', self::OUR_NUMBER);
        $conversation = new WhatsAppTransport()->conversation($connection, '+' . self::CUSTOMER);

        $this->assertSame(
            ConversationChannelService::channelSlug(self::CUSTOMER . '@s.whatsapp.net'),
            $conversation->channelSlug
        );
        $this->assertSame(self::CUSTOMER, $conversation->thread);
        $this->assertSame(self::OUR_NUMBER, $conversation->accountKey);
    }

    public function testALeadIsWrittenToThroughTheCompanysWhatsAppConnection(): void
    {
        $service = Mockery::mock(MessageService::class);
        $service->shouldReceive('sendTextMessage')->once()->with(self::CUSTOMER, 'Your car is ready')->andReturn(['data' => ['msgId' => 77]]);
        $this->transport($service);
        $connection = $this->defaultWhatsAppConnection();

        $result = new SendMessageToLeadAction($this->lead())->execute('whatsapp', 'Your car is ready');

        $this->assertSame('wasender', $result['provider']);
        $this->assertSame('77', $result['provider_message_id']);
        $this->assertSame(1, Delivery::query()->where('connection_id', $connection->getId())->where('status', DeliveryStatusEnum::ACCEPTED)->count());
    }

    public function testACompanyWithNoWhatsAppNumberCannotWriteToTheLead(): void
    {
        $result = new SendMessageToLeadAction($this->lead())->execute('whatsapp', 'Your car is ready');

        $this->assertFalse($result['success']);
        $this->assertSame('send_failed', $result['classification']);
    }

    public function testAStatusForALedgerSendMovesItsDelivery(): void
    {
        $service = Mockery::mock(MessageService::class);
        $service->shouldReceive('sendTextMessage')->andReturn(['data' => ['msgId' => 4411]]);
        $delivery = $this->send($this->transport($service), Content::text('Hello'));

        $result = $this->deliver(
            [['key' => ['id' => 'WA-KEY', 'remoteJid' => self::CUSTOMER . '@s.whatsapp.net'], 'msgId' => 4411, 'update' => ['status' => 3]]],
            WebhookEventEnum::MESSAGES_UPDATE,
            wrap: false
        );

        $this->assertSame(['recorded' => 1, 'duplicates' => 0, 'unmatched' => 0], $result['deliveries']);
        $this->assertSame(DeliveryStatusEnum::DELIVERED, $delivery->fresh()->status);
    }

    /**
     * WaSender echoes every send back, on `messages.upsert` and on `message.sent`. One that left
     * through the ledger was filed when it left.
     */
    public function testOurOwnLedgerSendIsNotFiledAgainWhenWaSenderEchoesIt(): void
    {
        $service = Mockery::mock(MessageService::class);
        $service->shouldReceive('sendTextMessage')->andReturn(['data' => ['msgId' => 5522]]);
        $this->send($this->transport($service), Content::text('Hello'));
        $filed = Message::query()->fromCompany($this->currentCompany()->getId())->count();

        $echo = [...$this->directText('WA-ECHO', fromMe: true), 'msgId' => 5522, 'status' => 1];

        $upsert = $this->deliver($echo);
        $sent = $this->deliver([...$echo, 'status' => 'sent'], WebhookEventEnum::MESSAGE_SENT, wrap: false);

        $this->assertSame([], $upsert['result']['messages']);
        $this->assertSame('our own send, already on the ledger', $upsert['result']['skipped'][0]['reason']);
        $this->assertFalse($sent['result']['filed']);
        $this->assertSame($filed, Message::query()->fromCompany($this->currentCompany()->getId())->count());
    }

    public function testAMessageTypedOnThePhoneItselfIsStillFiled(): void
    {
        $this->connection('wasender', self::OUR_NUMBER);

        $result = $this->deliver($this->directText('WAMSG-PHONE', fromMe: true));

        $this->assertCount(1, $result['result']['messages']);
        $this->assertTrue($result['result']['messages'][0]['is_from_me']);
    }

    /**
     * A payload mentions us by number or lid. The number is the connection's; the agent's own
     * record of it is only the fallback for a session that is not a connection yet.
     */
    public function testOurNumberForMentionsIsTheConnectionsBeforeTheAgentsOwnRecord(): void
    {
        $agent = $this->agent(company: $this->currentCompany());
        $agent->set(ConnectionFieldEnum::PHONE_NUMBER->value, 18095550777);
        $receiver = $this->receiver(['agent_id' => $agent->getId(), GroupMentionService::OWN_LID_KEY => '900']);

        try {
            $this->assertSame(['900', '18095550777'], new GroupMentionService($receiver, new Channel())->ownIdentities());

            $this->connection('wasender', self::OUR_NUMBER);

            $this->assertSame(['900', '18095550100'], new GroupMentionService($receiver, new Channel())->ownIdentities());
        } finally {
            $agent->del(ConnectionFieldEnum::PHONE_NUMBER->value);
        }
    }

    /**
     * The status query reads sessions with the app's account token, which reaches every company's.
     */
    public function testASessionIsOnlyACompanysWhenItsConnectionOrOneOfItsAgentsHoldsIt(): void
    {
        $company = Companies::factory()->create();
        $linked = $this->agent(company: $company);
        $linked->set(ConnectionFieldEnum::SESSION_ID->value, 4401);
        $this->connection('wasender', self::OUR_NUMBER, ['config' => ['session_id' => 4402]]);

        try {
            $this->assertTrue(WaSenderHandler::ownsSession($this->currentApp(), $company, 4401));
            $this->assertTrue(WaSenderHandler::ownsSession($this->currentApp(), $this->currentCompany(), 4402));
            $this->assertFalse(WaSenderHandler::ownsSession($this->currentApp(), $this->currentCompany(), 4401));
            $this->assertFalse(WaSenderHandler::ownsSession($this->currentApp(), $company, 4402));
            $this->assertFalse(WaSenderHandler::ownsSession($this->currentApp(), $company, 0));
        } finally {
            $linked->del(ConnectionFieldEnum::SESSION_ID->value);
        }
    }

    public function testTheStatusQueryRefusesASessionOfAnotherCompany(): void
    {
        $other = $this->agent(company: Companies::factory()->create());
        $other->set(ConnectionFieldEnum::SESSION_ID->value, 4410);

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('No WhatsApp session with that id on this company.');

            new WhatsappConnectorResolver()->status(null, ['session_id' => 4410]);
        } finally {
            $other->del(ConnectionFieldEnum::SESSION_ID->value);
        }
    }

    /**
     * A number linked by QR before WaSender was a provider is only known to its receiver.
     */
    public function testTheAccountOfANumberLinkedBeforeIsReadOffItsReceiver(): void
    {
        $company = Companies::factory()->create();
        $declared = $this->agent(['whatsapp_config' => ['phone_number' => '809 555 0100']], $company);
        $receiver = new CommunicationReceivers()->ensure($this->currentApp(), $company, 'wasender');
        $receiver->configuration = [...$receiver->configuration, 'agent_id' => $declared->getId(), 'session_id' => '909'];
        $receiver->saveOrFail();

        $this->assertSame(
            ['phone_number' => self::OUR_NUMBER, 'agent_id' => $declared->getId(), 'session_id' => 909],
            new WaSenderProvisioner()->legacySelection($this->integrationRow($company))
        );

        $linked = $this->agent(company: $company);
        $linked->set(ConnectionFieldEnum::PHONE_NUMBER->value, 18095550101);
        $receiver->configuration = [...$receiver->configuration, 'agent_id' => $linked->getId()];
        $receiver->saveOrFail();

        try {
            $this->assertSame('+18095550101', WaSenderHandler::selectionFromReceiver($receiver)['phone_number']);
        } finally {
            $linked->del(ConnectionFieldEnum::PHONE_NUMBER->value);
        }
    }

    public function testAnAgentOfAnotherCompanyNamedOnAReceiverIsNotSelected(): void
    {
        $company = Companies::factory()->create();
        $outsider = $this->agent(['whatsapp_config' => ['phone_number' => self::OUR_NUMBER]]);
        $receiver = new CommunicationReceivers()->ensure($this->currentApp(), $company, 'wasender');
        $receiver->configuration = [...$receiver->configuration, 'agent_id' => $outsider->getId()];
        $receiver->saveOrFail();

        $this->assertSame([], WaSenderHandler::selectionFromReceiver($receiver));
        $this->assertSame([], new WaSenderProvisioner()->legacySelection($this->integrationRow(Companies::factory()->create())));
    }

    public function testTheAdoptCommandGivesACompanyWithAReceiverTheIntegrationRowItsNumberIsSelectedOn(): void
    {
        $integration = Integrations::create([
            'apps_id' => $this->currentApp()->getId(),
            'name' => uniqid('wasender_'),
            'handler' => WaSenderHandler::class,
        ]);
        $this->region($this->currentCompany());
        $agent = $this->agent(['whatsapp_config' => ['phone_number' => self::OUR_NUMBER]]);
        $this->receiver(['agent_id' => $agent->getId(), 'session_id' => 909]);

        $this->artisan('kanvas:wasender:adopt-communication', [
            'app_id' => $this->currentApp()->getId(),
            '--company' => $this->currentCompany()->getId(),
        ])
            ->expectsOutputToContain('number=' . self::OUR_NUMBER)
            ->assertSuccessful();

        $row = IntegrationsCompany::query()
            ->fromApp($this->currentApp())
            ->fromCompany($this->currentCompany()->getId())
            ->where('integrations_id', $integration->getId())
            ->sole();

        $this->assertSame(
            ['phone_number' => self::OUR_NUMBER, 'agent_id' => $agent->getId(), 'session_id' => 909],
            $row->config
        );
    }

    public function testCleanupSwitchesOffTheWebhookItSetAndLeavesAnyOtherAlone(): void
    {
        $connection = $this->connection('wasender', self::OUR_NUMBER, ['config' => ['session_id' => 909]]);
        $ours = 'https://kanvas.test/v1/receiver/ours';
        $task = fn (string $type = WaSenderProvisioner::SESSION_WEBHOOK): CleanupTask => CleanupTask::query()->create([
            'apps_id' => $connection->apps_id,
            'companies_id' => $connection->companies_id,
            'connection_id' => $connection->getId(),
            'resource_type' => $type,
            'resource_key' => $connection->account_key,
            'external_id' => '909',
            'expected_value' => $ours,
        ]);

        $stillOurs = Mockery::mock(SessionService::class);
        $stillOurs->shouldReceive('getSession')->with(909)->andReturn(['webhook_url' => $ours]);
        $stillOurs->shouldReceive('updateSession')->once()->with(909, ['webhook_enabled' => false])->andReturn([]);

        $moved = Mockery::mock(SessionService::class);
        $moved->shouldReceive('getSession')->with(909)->andReturn(['webhook_url' => 'https://elsewhere.test/hook']);
        $moved->shouldNotReceive('updateSession');

        $deleted = Mockery::mock(SessionService::class);
        $deleted->shouldReceive('getSession')->with(909)->andThrow(new WaSenderRefusedException('Session not found', 404));
        $deleted->shouldNotReceive('updateSession');

        $this->assertSame(CleanupTaskStatusEnum::DONE, $this->provisioner($stillOurs)->cleanup($task()));
        $this->assertSame(CleanupTaskStatusEnum::SUPERSEDED, $this->provisioner($moved)->cleanup($task()));
        $this->assertSame(CleanupTaskStatusEnum::SUPERSEDED, $this->provisioner($deleted)->cleanup($task()));
        $this->assertSame(
            CleanupTaskStatusEnum::SUPERSEDED,
            $this->provisioner(Mockery::mock(SessionService::class))->cleanup($task('something_else'))
        );
    }

    public function testSetupPointsTheSessionAtTheReceiverAndKeepsItsSecret(): void
    {
        $this->currentApp()->set('wasender_personal_access_token', 'pat-test');
        $connection = $this->connection('wasender', self::OUR_NUMBER, ['config' => ['session_id' => 909]]);
        $receiver = $this->receiver();
        $session = ['webhook_url' => 'https://kanvas.test/v1/receiver/an-old-one', 'webhook_enabled' => true, 'webhook_secret' => 'fresh-secret'];
        $sessions = Mockery::mock(SessionService::class);
        $sessions->shouldReceive('getSession')->with(909)->andReturnUsing(function () use (&$session): array {
            return $session;
        });
        $sessions->shouldReceive('updateSession')->once()->andReturnUsing(function (int $id, array $data) use (&$session): array {
            $session = [...$session, ...$data];

            return $session;
        });

        try {
            $registration = $this->provisioner($sessions)->registerInbound($connection, $receiver->getUrl());
        } finally {
            $this->currentApp()->del('wasender_personal_access_token');
        }

        $this->assertSame($receiver->getUrl(), $session['webhook_url']);
        $this->assertSame('fresh-secret', $receiver->fresh()->configuration['webhook_secret']);
        $this->assertSame(WaSenderProvisioner::SESSION_WEBHOOK, $registration->owned[0]->type);
        $this->assertSame('909', $registration->owned[0]->externalId);
    }

    public function testASessionWiredByHandHasNothingToRegister(): void
    {
        $connection = $this->connection('wasender', self::OUR_NUMBER);

        $registration = $this->provisioner(Mockery::mock(SessionService::class))
            ->registerInbound($connection, 'https://kanvas.test/v1/receiver/x');

        $this->assertSame([], $registration->owned);
    }

    public function testTheAgentALegacyRuleNamedKeepsAnsweringAndTheRuleIsRetired(): void
    {
        $agent = $this->agent();
        $connection = $this->connection('wasender', self::OUR_NUMBER);
        $rule = $this->legacyResponderRule(RetireLegacyWhatsAppInboundAction::RESPONDER_ACTIVITY, $agent);

        $retired = new RetireLegacyWhatsAppInboundAction($connection)->execute();

        $this->assertContains('rule ' . $rule->name, $retired);
        $this->assertSame(1, (int) $rule->fresh()->is_deleted);
        $this->assertTrue($connection->fresh()->agent()->is($agent));
        $this->assertSame(self::OUR_NUMBER, $agent->fresh()->whatsappPhoneNumber());
    }

    public function testALegacyReceiverIsMovedOntoTheCommunicationWebhookInPlace(): void
    {
        $receiver = $this->legacyReceiver(
            WaSenderProvisioner::LEGACY_RECEIVER_JOB,
            ['agent_id' => 7, 'webhook_secret' => 's3cret', 'allowed_group_jids' => ['1203@g.us']]
        );

        (require database_path('migrations/Workflow/2026_11_14_100000_move_wasender_receivers_to_the_communication_webhook.php'))->up();

        $moved = new CommunicationReceivers()->find($this->currentApp(), $this->currentCompany(), 'wasender');

        $this->assertTrue($moved->is($receiver));
        $this->assertSame($receiver->uuid, $moved->uuid);
        $this->assertSame('s3cret', $moved->configuration['webhook_secret']);
        $this->assertSame(['1203@g.us'], $moved->configuration['allowed_group_jids']);
        $this->assertSame(7, $moved->configuration['agent_id']);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function agent(array $attributes = [], ?Companies $company = null): Agent
    {
        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId(($company ?? $this->currentCompany())->getId())
            ->create($attributes);
    }

    /**
     * On a company of its own: the shared test company keeps agents other tests linked a number
     * to, and a linked number lives in settings no transaction rolls back.
     *
     * @param array<string, mixed> $config
     */
    private function integrationRow(Companies $company, array $config = []): IntegrationsCompany
    {
        $row = new IntegrationsCompany([
            'companies_id' => $company->getId(),
            'apps_id' => $this->currentApp()->getId(),
            'config' => $config ?: null,
        ]);
        $row->setRelation('company', $company);
        $row->setRelation('app', $this->currentApp());

        return $row;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function receiver(array $configuration = []): ReceiverWebhook
    {
        $receiver = new CommunicationReceivers()->ensure($this->currentApp(), $this->currentCompany(), 'wasender');

        if ($configuration !== []) {
            $receiver->configuration = [...$receiver->configuration, ...$configuration];
            $receiver->saveOrFail();
        }

        return $receiver;
    }

    private function request(?string $signature): Request
    {
        $request = Request::create('/v1/receiver/x', 'POST');

        if ($signature !== null) {
            $request->headers->set('x-webhook-signature', $signature);
        }

        return $request;
    }

    /**
     * Through the real communication webhook, on a receiver that names its provider.
     *
     * @return array<string, mixed>
     */
    /**
     * Every turn this test announced is one the app default rule's activity leaves alone.
     */
    private function assertNoTurnIsAnswered(): void
    {
        foreach ($this->firedWith($this->turns) as $turn) {
            $this->assertNull(
                new RespondToInboundMessageAction($turn['message']->channels()->firstOrFail(), $turn['message'])->executeIfOwned(),
                'nobody may answer this message'
            );
        }
    }

    private function deliver(
        array $data,
        WebhookEventEnum $event = WebhookEventEnum::MESSAGES_UPSERT,
        bool $wrap = true
    ): array {
        $receiver = $this->receiver();

        return new ProcessCommunicationWebhookJob(ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => $receiver->getUrl(),
            'headers' => [],
            'payload' => ['event' => $event->value, 'data' => $wrap ? ['messages' => $data] : $data],
        ]))->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function directText(string $messageId, bool $fromMe = false): array
    {
        return [
            'key' => [
                'id' => $messageId . '-' . uniqid(),
                'fromMe' => $fromMe,
                'remoteJid' => self::CUSTOMER . '@s.whatsapp.net',
            ],
            'pushName' => 'Dana Cruz',
            'remoteJid' => self::CUSTOMER . '@s.whatsapp.net',
            'messageBody' => 'hola, quiero informacion',
            'message' => ['extendedTextMessage' => ['text' => 'hola, quiero informacion']],
        ];
    }

    /**
     * The transport the container hands out, with WaSender's API stood in for and no waiting
     * between parts.
     */
    private function transport(MessageService $service): WhatsAppTransport
    {
        $transport = new class ($service) extends WhatsAppTransport {
            public function __construct(private readonly MessageService $service)
            {
            }

            protected function messageService(Delivery $delivery): MessageService
            {
                return $this->service;
            }

            protected function pause(): void
            {
            }
        };

        $this->app->bind(WhatsAppTransport::class, fn () => $transport);

        return $transport;
    }

    private function provisioner(SessionService $sessions): WaSenderProvisioner
    {
        return new class ($sessions) extends WaSenderProvisioner {
            public function __construct(private readonly SessionService $fakeSessions)
            {
            }

            protected function sessions(Connection $connection): SessionService
            {
                return $this->fakeSessions;
            }
        };
    }

    private function defaultWhatsAppConnection(): Connection
    {
        return $this->defaultConnection(ChannelTypeEnum::WHATSAPP, 'wasender', self::OUR_NUMBER);
    }

    private function send(WhatsAppTransport $transport, Content $content): Delivery
    {
        $this->defaultWhatsAppConnection();

        return $this->communication()
            ->send(
                $this->person(),
                $content,
                channels: ['whatsapp'],
                sync: true
            )
            ->delivery(ChannelTypeEnum::WHATSAPP);
    }

    private function person(): People
    {
        $people = People::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create();
        $people->addContact(ContactTypeEnum::CELLPHONE->getName(), '+' . self::CUSTOMER);

        return $people;
    }

    private function lead(): Lead
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->currentApp()->getId(), $this->currentCompany()->getId())
            ->create();
        $lead->people->contacts()->delete();
        $lead->people->addContact(ContactTypeEnum::CELLPHONE->getName(), '+' . self::CUSTOMER);

        return $lead;
    }
}
