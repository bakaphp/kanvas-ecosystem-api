<?php

declare(strict_types=1);

namespace Tests\Communication\Channels\WhatsApp\Providers\Meta;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\MetaInbound;
use Kanvas\Communication\Connections\Actions\EnsureCommunicationSetupAction;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\InboundClaim;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\ConversationAgentEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Communication\Workflows\AgentChannelResponderActivity;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\StoredWorkflow;
use PHPUnit\Framework\Attributes\Group;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

/**
 * WhatsApp Cloud end to end on the shared pipeline: the one webhook job takes Meta's payloads, the
 * one responder answers on the number the customer wrote to.
 */
// Sets the Meta app secret, an app setting every paratest process shares through Redis.
#[Group('serial')]
final class MetaWhatsAppProviderTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string SECRET = 'meta-test-secret';
    private const string OUR_NUMBER = '+18095550100';
    private const string PHONE_NUMBER_ID = '106540352242922';
    private const string WABA_ID = '102290129340398';

    /**
     * The app's own Meta settings, put back after each test: the test app is the same app a local
     * WhatsApp integration runs on, and deleting them cuts its live webhooks off.
     *
     * @var array<string, array{value: mixed, secret: bool}>
     */
    private array $appSettingsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['communication.providers' => [MetaWhatsAppHandler::class]]);

        foreach ([ConfigurationEnum::APP_SECRET, ConfigurationEnum::WEBHOOK_VERIFY_TOKEN] as $key) {
            $this->appSettingsBefore[$key->value] = [
                'value' => $this->currentApp()->get($key->value),
                'secret' => $this->currentApp()->isSecret($key->value),
            ];
        }

        $this->currentApp()->set(ConfigurationEnum::APP_SECRET->value, self::SECRET);
        $this->currentApp()->set(ConfigurationEnum::WEBHOOK_VERIFY_TOKEN->value, 'verify-me');
        $company = $this->currentCompany();
        $company->set(ConfigurationEnum::PHONE_NUMBER_ID->value, self::PHONE_NUMBER_ID);
        $company->setEncrypted(ConfigurationEnum::ACCESS_TOKEN->value, 'company-token');
        $company->set(ConfigurationEnum::DISPLAY_PHONE_NUMBER->value, '+1 809-555-0100');
        $company->set(ConfigurationEnum::WABA_ID->value, self::WABA_ID);
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());

        LeadType::firstOrCreate(
            ['apps_id' => $this->currentApp()->getId(), 'companies_id' => $company->getId(), 'name' => 'Warm'],
            ['description' => 'Warm Lead Type', 'is_active' => true, 'uuid' => Str::uuid()]
        );
    }

    /**
     * Settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        foreach ($this->appSettingsBefore as $key => ['value' => $value, 'secret' => $secret]) {
            match (true) {
                $value === null => $this->currentApp()->del($key),
                $secret => $this->currentApp()->setEncrypted($key, $value),
                default => $this->currentApp()->set($key, $value),
            };
        }

        foreach (ConfigurationEnum::cases() as $key) {
            $this->currentCompany()->del($key->value);
        }

        $this->currentCompany()->del(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value);

        parent::tearDown();
    }

    public function testOnlyABodySignedWithTheAppSecretIsAccepted(): void
    {
        $receiver = $this->receiver();
        $body = json_encode($this->inboundPayload('wamid.SIG', '+18095550201', 'Hola'));

        $this->assertTrue(ProcessCommunicationWebhookJob::authenticateRequest($this->signedRequest($receiver, $body), $receiver));
        $this->assertFalse(ProcessCommunicationWebhookJob::authenticateRequest(
            $this->signedRequest($receiver, $body, signature: 'sha256=' . hash_hmac('sha256', $body, 'someone-else')),
            $receiver
        ));
        $this->assertFalse(ProcessCommunicationWebhookJob::authenticateRequest(
            $this->signedRequest($receiver, $body . ' ', signature: 'sha256=' . hash_hmac('sha256', $body, self::SECRET)),
            $receiver
        ), 'the signature covers the exact bytes');

        $this->currentApp()->del(ConfigurationEnum::APP_SECRET->value);
        $this->assertFalse(ProcessCommunicationWebhookJob::authenticateRequest($this->signedRequest($receiver, $body), $receiver));
    }

    public function testMetaVerifiesTheReceiverWithAGetChallenge(): void
    {
        $url = '/v1/receiver/' . $this->receiver()->uuid;

        $this->get($url . '?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1158201444')
            ->assertOk()
            ->assertSeeText('1158201444')
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->get($url . '?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1158201444')->assertForbidden();
    }

    public function testInboundIsFiledOnTheConnectionAndTheContactsWhatsAppChannel(): void
    {
        $connection = $this->metaConnection();

        $result = $this->deliver($this->inboundPayload('wamid.IN1', '18095550202', 'Is it still available?'));

        $message = Message::query()->findOrFail($result[0]['message_id']);
        $channel = Channel::query()->findOrFail($result[0]['channel_id']);

        $this->assertSame('Is it still available?', $message->message['content']);
        $this->assertSame('meta-whatsapp', $message->messageType->verb);
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
        $this->assertSame('whatsapp', $message->message['communication']['channel']);
        $this->assertSame('+18095550202', $message->message['communication']['thread']);
        $this->assertSame('wa-chat-8095550202-at-swhatsappnet', $channel->slug);
    }

    /**
     * Meta posts every workspace's numbers to the one URL; the claim, not the receiver, says whose
     * a message is.
     */
    public function testEveryWorkspaceOnTheAppSharesOneReceiver(): void
    {
        $receivers = app(CommunicationReceivers::class);

        $this->assertSame(
            $receivers->ensure($this->currentApp(), $this->currentCompany(), 'meta')->getId(),
            $receivers->ensure($this->currentApp(), $this->otherCompany(), 'meta')->getId()
        );
    }

    public function testInboundIsFiledForTheWorkspaceThatClaimsTheNumberWhoeverOwnsTheReceiver(): void
    {
        $owner = $this->otherCompany();
        $receiver = $this->receiver();
        $receiver->forceFill(['companies_id' => $owner->getId(), 'users_id' => $owner->users_id])->saveOrFail();
        $this->metaConnection();

        $result = $this->deliver($this->inboundPayload('wamid.IN8', '18095550208', 'Hola'));

        $this->assertSame($this->currentCompany()->getId(), (int) Message::query()->findOrFail($result[0]['message_id'])->companies_id);
    }

    public function testANumberNoWorkspaceClaimsIsNotFiled(): void
    {
        $result = $this->deliver($this->inboundPayload('wamid.IN9', '18095550209', 'Hola'));

        $this->assertFalse($result[0]['filed']);
        $this->assertSame('identity_not_connected', $result[0]['reason']);
    }

    public function testARedeliveredMessageIsFiledOnce(): void
    {
        $this->metaConnection();

        $first = $this->deliver($this->inboundPayload('wamid.IN2', '18095550203', 'Hola'));
        $again = $this->deliver($this->inboundPayload('wamid.IN2', '18095550203', 'Hola'));

        $this->assertSame($first[0]['message_id'], $again[0]['message_id']);
    }

    public function testAReactionIsNotATurn(): void
    {
        $this->metaConnection();
        $payload = $this->inboundPayload('wamid.IN3', '18095550204', 'x');
        $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
            'from' => '18095550204', 'id' => 'wamid.IN3', 'type' => 'reaction', 'reaction' => ['emoji' => '👍'],
        ];

        $this->assertSame([], $this->deliver($payload));
    }

    public function testASendGoesOutFromTheNumberAndItsStatusesNeverRegress(): void
    {
        $this->fakeGraph(['messages' => [['id' => 'wamid.OUT1']]]);
        $this->metaConnection(default: true);

        $delivery = $this->communication()
            ->send($this->personWithCellphone('+18095550205'), 'Your quote is ready', channels: ['whatsapp'], sync: true)
            ->delivery(ChannelTypeEnum::WHATSAPP);

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/' . self::PHONE_NUMBER_ID . '/messages')
            && $request->hasHeader('Authorization', 'Bearer company-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '18095550205'
            && $request['text']['body'] === 'Your quote is ready');
        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame('wamid.OUT1', $delivery->external_id);

        $delivered = $this->statusPayload('wamid.OUT1', 'delivered', 1700000100);
        $this->assertSame(['recorded' => 1, 'duplicates' => 0, 'unmatched' => 0], $this->deliver($delivered));
        $this->assertSame(['recorded' => 0, 'duplicates' => 1, 'unmatched' => 0], $this->deliver($delivered));
        $this->deliver($this->statusPayload('wamid.OUT1', 'sent', 1700000050));

        $delivery->refresh();
        $this->assertSame(DeliveryStatusEnum::DELIVERED, $delivery->status, 'a late sent does not move it back');
        $this->assertNull($delivery->events_closed_at, 'read can still follow delivered');
        $this->assertSame(2, $delivery->events()->count());

        $this->deliver($this->statusPayload('wamid.OUT1', 'read', 1700000200));
        $this->assertSame(DeliveryStatusEnum::READ, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->events_closed_at);
    }

    public function testARefusalOutsideTheServiceWindowFailsTheDelivery(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'code' => 131047,
            'message' => 'Re-engagement message',
            'error_data' => ['details' => 'More than 24 hours have passed since the recipient last replied.'],
        ]], 400)]);
        $this->metaConnection(default: true);

        $delivery = $this->communication()
            ->send($this->personWithCellphone('+18095550206'), 'Following up', channels: ['whatsapp'], sync: true)
            ->delivery(ChannelTypeEnum::WHATSAPP);

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->status);
        $this->assertSame('outside_service_window', $delivery->error_code);
        $this->assertStringContainsString('24 hours', (string) $delivery->error);
    }

    public function testTheResponderAnswersAWhatsAppMessageOnTheNumberItArrivedOn(): void
    {
        $this->fakeGraph(['messages' => [['id' => 'wamid.REPLY1']]]);
        $connection = $this->metaConnection();
        $result = $this->deliver($this->inboundPayload('wamid.IN4', '18095550207', 'Is it still available?'));
        $inbound = Message::query()->findOrFail($result[0]['message_id']);
        $this->agent();

        $response = new AgentChannelResponderActivity(0, now()->toDateTimeString(), new StoredWorkflow(), [])->execute(
            Channel::query()->findOrFail($result[0]['channel_id']),
            $this->currentApp(),
            ['message' => $inbound]
        );

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();

        $this->assertSame('Hola Mundo', $response['response'] ?? null, 'no SMS opt-out clause on WhatsApp');
        $this->assertSame(ChannelTypeEnum::WHATSAPP, $delivery->channel);
        $this->assertSame('+18095550207', $delivery->to_address);
        $this->assertSame('wamid.REPLY1', $delivery->external_id);
        $this->assertSame('meta-whatsapp', Message::query()->findOrFail($delivery->message_id)->messageType->verb);
        Http::assertSent(fn (HttpRequest $request): bool => $request['to'] === '18095550207' && $request['text']['body'] === 'Hola Mundo');
    }

    public function testSetupSubscribesTheWabaToTheTenantsReceiverAndReadsItBack(): void
    {
        $receiverUrl = app(CommunicationReceivers::class)->ensure($this->currentApp(), $this->currentCompany(), 'meta')->getUrl();
        $subscribed = [];
        Http::fake(function (HttpRequest $request) use (&$subscribed, $receiverUrl) {
            if (str_ends_with($request->url(), '/subscribed_apps') && $request->method() === 'POST') {
                $subscribed[] = $request->data();

                return Http::response(['success' => true]);
            }

            if (str_contains($request->url(), '/subscribed_apps')) {
                return Http::response(['data' => $subscribed === [] ? [] : [['override_callback_uri' => $receiverUrl]]]);
            }

            return Http::response(['id' => self::PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0100']);
        });

        $report = new EnsureCommunicationSetupAction($this->currentApp(), $this->integrationCompany())->execute();

        $connection = Connection::query()->where('provider', 'meta')->where('companies_id', $this->currentCompany()->getId())->sole();
        $this->assertSame(SetupStatusEnum::READY, $connection->setup_status, json_encode($report));
        $this->assertTrue($connection->enabled);
        $this->assertSame(self::OUR_NUMBER, $connection->account_key);
        $this->assertEquals(['phone_number_id' => self::PHONE_NUMBER_ID, 'waba_id' => self::WABA_ID], $connection->config);
        $this->assertSame([['override_callback_uri' => $receiverUrl, 'verify_token' => 'verify-me']], $subscribed);
        $this->assertSame($connection->getId(), InboundClaim::query()->where('provider', 'meta')->where('inbound_identity', self::OUR_NUMBER)->value('connection_id'));
    }

    public function testAWabaAlreadySendingToAnotherKanvasReceiverIsNotTakenOver(): void
    {
        $elsewhere = rtrim((string) config('app.url'), '/') . '/v1/receiver/' . Str::uuid();
        Http::fake(function (HttpRequest $request) use ($elsewhere) {
            if (str_contains($request->url(), '/subscribed_apps')) {
                return $request->method() === 'GET'
                    ? Http::response(['data' => [['override_callback_uri' => $elsewhere]]])
                    : Http::response(['success' => true]);
            }

            return Http::response(['id' => self::PHONE_NUMBER_ID]);
        });

        new EnsureCommunicationSetupAction($this->currentApp(), $this->integrationCompany())->execute();

        $connection = Connection::query()->where('provider', 'meta')->where('companies_id', $this->currentCompany()->getId())->sole();
        $this->assertSame(SetupStatusEnum::BLOCKED, $connection->setup_status);
        $this->assertStringStartsWith('foreign_registration', (string) $connection->last_error);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'POST');
    }

    public function testAVerifyTokenIsGeneratedOnceWhenNoneIsSet(): void
    {
        $this->currentApp()->del(ConfigurationEnum::WEBHOOK_VERIFY_TOKEN->value);

        $token = MetaInbound::verifyToken($this->currentApp());

        $this->assertSame(40, strlen($token));
        $this->assertSame($token, MetaInbound::verifyToken($this->currentApp()));
        $this->assertTrue($this->currentApp()->isSecret(ConfigurationEnum::WEBHOOK_VERIFY_TOKEN->value));
    }

    private function fakeGraph(array $sendResponse): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($sendResponse)]);
    }

    private function metaConnection(bool $default = false): Connection
    {
        $connection = $this->connection('meta', self::OUR_NUMBER, [
            'config' => ['phone_number_id' => self::PHONE_NUMBER_ID, 'waba_id' => self::WABA_ID],
        ]);
        $this->claim($connection);

        if ($default) {
            ChannelDefault::updateOrCreate(
                ['apps_id' => $connection->apps_id, 'companies_id' => $connection->companies_id, 'channel' => 'whatsapp'],
                ['connection_id' => $connection->getId()]
            );
        }

        return $connection;
    }

    private function integrationCompany(): IntegrationsCompany
    {
        $integration = Integrations::create([
            'apps_id' => 0,
            'name' => uniqid('meta_whatsapp_'),
            'handler' => MetaWhatsAppHandler::class,
        ]);

        return IntegrationsCompany::create([
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $this->currentCompany()->getId(),
            'integrations_id' => $integration->getId(),
            'region_id' => 0,
            'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
        ]);
    }

    private function receiver(): ReceiverWebhook
    {
        return app(CommunicationReceivers::class)->ensure($this->currentApp(), $this->currentCompany(), 'meta');
    }

    private function signedRequest(ReceiverWebhook $receiver, string $body, ?string $signature = null): Request
    {
        $request = Request::create(
            $receiver->getUrl(),
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body
        );
        $request->headers->set('X-Hub-Signature-256', $signature ?? 'sha256=' . hash_hmac('sha256', $body, self::SECRET));

        return $request;
    }

    private function deliver(array $payload): array
    {
        $receiver = $this->receiver();
        $call = new ProcessWebhookAttemptAction($receiver, $this->signedRequest($receiver, json_encode($payload)))->execute();

        return new ProcessCommunicationWebhookJob($call)->handle();
    }

    private function inboundPayload(string $id, string $from, string $text): array
    {
        return $this->payload([
            'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => $text]]],
        ]);
    }

    private function statusPayload(string $id, string $status, int $timestamp): array
    {
        return $this->payload([
            'statuses' => [['id' => $id, 'status' => $status, 'timestamp' => (string) $timestamp, 'recipient_id' => '18095550205']],
        ]);
    }

    private function payload(array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => self::WABA_ID,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '18095550100', 'phone_number_id' => self::PHONE_NUMBER_ID],
                    ] + $value,
                ]],
            ]],
        ];
    }

    private function agent(): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($this->currentApp()->getId())
            ->create(['name' => 'Sales (Neuron Test)', 'provider' => 'neuron', 'handler' => SalesNeuronAgentStub::class]);

        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create([
                'name' => ConversationAgentEnum::DEFAULT_NAME->value,
                'agent_type_id' => $agentType->getId(),
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);
    }
}
