<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Meta;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\EnsureCommunicationSetupAction;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\InboundClaim;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\Actions\RespondToInboundMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Setup\Jobs\SetupPeopleChannelsJob;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Connectors\Meta\Enums\ConfigurationEnum;
use Kanvas\Connectors\Meta\Enums\PersonCustomFieldEnum;
use Kanvas\Connectors\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Connectors\Meta\MetaInbound;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\RemoveIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Concerns\RefusesParallelRuns;
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
    use RefusesParallelRuns;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string SECRET = 'meta-test-secret';
    private const string OUR_NUMBER = '+18095550100';
    private const string PHONE_NUMBER_ID = '106540352242922';
    private const string SECOND_NUMBER = '+18095550200';
    private const string SECOND_PHONE_NUMBER_ID = '106540352242933';
    private const string WABA_ID = '102290129340398';
    private const string USER_ID = 'DO.1632950178139894';
    private const string PARENT_USER_ID = 'US.ENT.11815799212886844830';

    /**
     * The app's own Meta settings, put back after each test: the test app is the same app a local
     * WhatsApp integration runs on, and deleting them cuts its live webhooks off.
     *
     * @var array<string, array{value: mixed, secret: bool}>
     */
    private array $appSettingsBefore = [];

    private ?Integrations $metaIntegration = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refuseParallelRun();

        Queue::fake();
        // A test that fakes too little fails here instead of asking Meta.
        Http::preventStrayRequests();
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

    public function testMetaVerifiesTheSharedCallbackWithThePlatformsToken(): void
    {
        $this->assertSame($this->currentApp()->getId(), CommunicationReceivers::platformApp()->getId());

        $this->get('/v1/receiver/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1158201444')
            ->assertOk()
            ->assertSeeText('1158201444');

        $this->get('/v1/receiver/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1158201444')->assertForbidden();
    }

    public function testTheSharedCallbackGoesToTheReceiverOfTheAppThatClaimsTheNumber(): void
    {
        $this->metaConnection();
        $receiver = $this->receiver();
        $before = $receiver->webhookCalls()->count();

        $this->postToCallback(json_encode($this->inboundPayload('wamid.CB1', '18095550211', 'Hola')))->assertOk();
        $this->postToCallback(json_encode($this->inboundPayload('wamid.CB2', '18095550212', 'Hola')), 'someone-else')
            ->assertUnauthorized();

        $this->assertSame($before + 1, $receiver->webhookCalls()->count());
    }

    public function testTheSharedCallbackAcknowledgesANumberNoCompanyClaims(): void
    {
        $receiver = $this->receiver();
        $before = $receiver->webhookCalls()->count();

        $this->postToCallback(json_encode($this->inboundPayload('wamid.CB3', '18095550213', 'Hola')))->assertOk();

        $this->assertSame($before, $receiver->webhookCalls()->count());
    }

    /**
     * The request runs as the app of the company that claims the number: that app's secret signs it
     * and that app's receiver takes it.
     */
    public function testTheSharedCallbackRunsAsTheAppOfTheCompanyThatClaimsTheNumber(): void
    {
        $app = $this->currentApp();
        $ownReceiver = $this->receiver();
        $ownCalls = $ownReceiver->webhookCalls()->count();
        $otherApp = $this->otherApp();
        $otherApp->set(ConfigurationEnum::APP_SECRET->value, 'other-app-secret');
        $this->metaConnection();
        InboundClaim::query()->where('provider', 'meta')->where('inbound_identity', self::OUR_NUMBER)->update(['apps_id' => $otherApp->getId()]);
        $otherReceiver = new CommunicationReceivers()->ensure($otherApp, $this->currentCompany(), 'meta');
        $body = json_encode($this->inboundPayload('wamid.CB4', '18095550214', 'Hola'));

        try {
            $this->postToCallback($body)->assertUnauthorized();
            $this->postToCallback($body, 'other-app-secret')->assertOk();

            $this->assertSame($otherApp->getId(), app(Apps::class)->getId());
            $this->assertSame(1, $otherReceiver->webhookCalls()->count());
            $this->assertSame($ownCalls, $ownReceiver->webhookCalls()->count());
        } finally {
            $this->overwriteAppService($app);
            $otherApp->del(ConfigurationEnum::APP_SECRET->value);
        }
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
        $receivers = new CommunicationReceivers();

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

    public function testTheSendersProfileNamesANewPersonAndTheirLead(): void
    {
        $this->metaConnection();

        $people = $this->personBehind($this->deliver($this->inboundFrom('wamid.NAME1', '18095550210', [
            'profile' => ['name' => 'Max Castro', 'username' => 'kaiokenmc'],
            'user_id' => 'DO.2404917373305418',
        ])));

        $this->assertSame('Max', $people->firstname);
        $this->assertSame('Castro', $people->lastname);
        $this->assertSame('kaiokenmc', $people->get(PersonCustomFieldEnum::USERNAME->value));
        $this->assertSame('DO.2404917373305418', $people->get(PersonCustomFieldEnum::USER_ID->value));
        $this->assertSame(
            'Max Castro Meta Opp',
            Lead::query()->where('people_id', $people->getId())->firstOrFail()->title
        );
    }

    public function testAPersonFiledUnderTheirNumberTakesTheProfileNameOnTheirNextMessage(): void
    {
        $this->metaConnection();

        $unnamed = $this->personBehind($this->deliver($this->inboundFrom('wamid.NAME2', '18095550211', [])));
        $this->assertSame('8095550211', $unnamed->firstname);

        $named = $this->personBehind($this->deliver($this->inboundFrom('wamid.NAME3', '18095550211', [
            'profile' => ['name' => 'Max Castro'],
        ])));

        $this->assertSame($unnamed->getId(), $named->getId());
        $this->assertSame('Max', $named->firstname);
        $this->assertSame('Castro', $named->lastname);
    }

    public function testAProfileListedUnderMetasUserIdStillNamesThePerson(): void
    {
        $this->metaConnection();
        $payload = $this->inboundFrom('wamid.NAME6', '18095550213', [
            'wa_id' => '18095550999',
            'user_id' => 'DO.77',
            'profile' => ['name' => 'Max Castro'],
        ]);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['from_user_id'] = 'DO.77';

        $this->assertSame('Max', $this->personBehind($this->deliver($payload))->firstname);
    }

    /**
     * Someone with a WhatsApp username: Meta sends its own id for them and no number at all.
     */
    public function testAMessageWithoutANumberIsFiledUnderMetasUserId(): void
    {
        $connection = $this->metaConnection();

        $result = $this->deliver($this->inboundFromUserId('wamid.BSUID1', self::USER_ID, 'Hola Sally'));

        $message = Message::query()->findOrFail($result[0]['message_id']);
        $channel = Channel::query()->findOrFail($result[0]['channel_id']);
        $people = $this->personBehind($result);

        $this->assertSame('Hola Sally', $message->message['content']);
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
        $this->assertSame(self::USER_ID, $message->message['communication']['thread']);
        $this->assertSame('wa-chat-' . self::USER_ID . '-at-swhatsappnet', $channel->slug);
        $this->assertSame('Beno', $people->firstname);
        $this->assertSame('Suriano Siu', $people->lastname);
        $this->assertSame('beno7surianosiu', $people->get(PersonCustomFieldEnum::USERNAME->value));
        $this->assertSame(self::USER_ID, $people->get(PersonCustomFieldEnum::USER_ID->value));
        $this->assertSame(0, $people->contacts()->count(), 'the id is not a number to call');
    }

    public function testTheSameUserIdKeepsItsPersonAndItsConversation(): void
    {
        $this->metaConnection();

        $first = $this->deliver($this->inboundFromUserId('wamid.BSUID2', self::USER_ID, 'Hola'));
        $second = $this->deliver($this->inboundFromUserId('wamid.BSUID3', self::USER_ID, 'Sigues ahi?'));

        $this->assertNotSame($first[0]['message_id'], $second[0]['message_id']);
        $this->assertSame($first[0]['channel_id'], $second[0]['channel_id']);
        $this->assertSame($this->personBehind($first)->getId(), $this->personBehind($second)->getId());
    }

    /**
     * Meta starts sending the number once the two have talked; that is not a second person.
     */
    public function testAUserIdOnFileIsTheSamePersonOnceMetaSendsTheirNumber(): void
    {
        $this->metaConnection();

        $known = $this->personBehind($this->deliver($this->inboundFromUserId('wamid.BSUID4', self::USER_ID, 'Hola')));
        $payload = $this->inboundFrom('wamid.BSUID5', '18095550214', [
            'profile' => ['name' => 'Beno Suriano Siu'],
            'user_id' => self::USER_ID,
        ]);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['from_user_id'] = self::USER_ID;

        $people = $this->personBehind($this->deliver($payload));

        $this->assertSame($known->getId(), $people->getId());
        $this->assertSame('8095550214', $people->contacts()->sole()->value);
    }

    public function testTheResponderAnswersAUserIdAsTheRecipient(): void
    {
        $this->fakeGraph(['messages' => [['id' => 'wamid.REPLY2']]]);
        $connection = $this->metaConnection();
        $result = $this->deliver($this->inboundFromUserId('wamid.BSUID6', self::USER_ID, 'Hola Sally'));
        $connection->forceFill(['config' => [...($connection->config ?? []), 'agent_id' => $this->agent()->getId()]])->saveOrFail();

        new RespondToInboundMessageAction(
            Channel::query()->findOrFail($result[0]['channel_id']),
            Message::query()->findOrFail($result[0]['message_id'])
        )->execute();

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame(self::USER_ID, $delivery->to_address);
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/messages')
            && $request['recipient'] === self::USER_ID
            && ! isset($request['to'])
            && $request['text']['body'] === 'Hola Mundo');
    }

    #[DataProvider('malformedUserIds')]
    public function testAUserIdThatIsNotOneOfMetasIsNotFiled(string $userId): void
    {
        $this->metaConnection();

        $this->assertSame([], $this->deliver($this->inboundFromUserId('wamid.BSUID7', $userId, 'Hola')));
    }

    public static function malformedUserIds(): array
    {
        return [
            'digits a number could be read from' => ['DO_18095551234'],
            'lowercase country' => ['do.1632950178139894'],
            'no country' => ['1632950178139894'],
        ];
    }

    public function testAParentUserIdIsAnsweredAsTheRecipient(): void
    {
        $this->fakeGraph(['messages' => [['id' => 'wamid.REPLY3']]]);
        $this->metaConnection();
        $result = $this->deliver($this->inboundFromUserId('wamid.BSUID8', self::PARENT_USER_ID, 'Hola'));
        $inbound = Message::query()->findOrFail($result[0]['message_id']);

        $delivery = $this->communication()
            ->reply($inbound, 'Hola', sync: true)
            ->delivery(ChannelTypeEnum::WHATSAPP);

        $this->assertSame(self::PARENT_USER_ID, $inbound->message['communication']['thread']);
        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        Http::assertSent(fn (HttpRequest $request): bool => $request['recipient'] === self::PARENT_USER_ID && ! isset($request['to']));
    }

    /**
     * The id opens its own conversation: nothing in the payload says which number it belongs to,
     * only the person on file does.
     */
    public function testAPersonOnFileByNumberIsTheSameOneWritingUnderTheirUserId(): void
    {
        $this->metaConnection();
        $byNumber = $this->deliver($this->inboundFrom('wamid.BSUID9', '18095550215', [
            'profile' => ['name' => 'Beno Suriano Siu'],
            'user_id' => self::USER_ID,
        ]));

        $byUserId = $this->deliver($this->inboundFromUserId('wamid.BSUID10', self::USER_ID, 'Hola'));
        $people = $this->personBehind($byUserId);

        $this->assertSame($this->personBehind($byNumber)->getId(), $people->getId());
        $this->assertSame('8095550215', $people->contacts()->sole()->value, 'the number on file stays');
        $this->assertNotSame($byNumber[0]['channel_id'], $byUserId[0]['channel_id']);
        $this->assertSame(
            'wa-chat-' . self::USER_ID . '-at-swhatsappnet',
            Channel::query()->findOrFail($byUserId[0]['channel_id'])->slug
        );
    }

    public function testAUserIdMetaListsNoContactForIsStillFiled(): void
    {
        $this->metaConnection();
        $payload = $this->inboundFromUserId('wamid.BSUID11', self::USER_ID, 'Hola');
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [];

        $people = $this->personBehind($this->deliver($payload));

        $this->assertSame(self::USER_ID, $people->firstname);
        $this->assertSame(self::USER_ID, $people->get(PersonCustomFieldEnum::USER_ID->value));
        $this->assertSame(0, $people->contacts()->count());
    }

    public function testAnAddressThatIsNeitherANumberNorAUserIdIsNeverSentToItsDigits(): void
    {
        Http::fake();
        $this->metaConnection();
        $result = $this->deliver($this->inboundPayload('wamid.IN9', '18095550216', 'Hola'));
        $inbound = Message::query()->findOrFail($result[0]['message_id']);
        $inbound->message = array_replace_recursive($inbound->message, ['communication' => ['thread' => 'do.1632950178139894']]);
        $inbound->saveOrFail();

        $delivery = $this->communication()
            ->reply($inbound, 'Hola', sync: true)
            ->delivery(ChannelTypeEnum::WHATSAPP);

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->status);
        $this->assertSame('invalid_address', $delivery->error_code);
        Http::assertNothingSent();
    }

    public function testANameAlreadyOnFileIsNotReplacedByTheProfileName(): void
    {
        $this->metaConnection();

        $this->deliver($this->inboundFrom('wamid.NAME4', '18095550212', [
            'profile' => ['name' => 'Ana Perez'],
        ]));
        $people = $this->personBehind($this->deliver($this->inboundFrom('wamid.NAME5', '18095550212', [
            'profile' => ['name' => 'Max Castro'],
        ])));

        $this->assertSame('Ana', $people->firstname);
        $this->assertSame('Perez', $people->lastname);
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
            ->send(
                $this->personWithCellphone('+18095550205'),
                'Your quote is ready',
                channels: ['whatsapp'],
                sync: true
            )
            ->delivery(ChannelTypeEnum::WHATSAPP);

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/' . self::PHONE_NUMBER_ID . '/messages')
            && $request->hasHeader('Authorization', 'Bearer company-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '18095550205'
            && ! isset($request['recipient'])
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
            ->send(
                $this->personWithCellphone('+18095550206'),
                'Following up',
                channels: ['whatsapp'],
                sync: true
            )
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
        $connection->forceFill(['config' => [...($connection->config ?? []), 'agent_id' => $this->agent()->getId()]])->saveOrFail();

        $response = new RespondToInboundMessageAction(
            Channel::query()->findOrFail($result[0]['channel_id']),
            $inbound
        )->execute();

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();

        $this->assertSame('Hola Mundo', $response['response'] ?? null, 'no SMS opt-out clause on WhatsApp');
        $this->assertSame(ChannelTypeEnum::WHATSAPP, $delivery->channel);
        $this->assertSame('+18095550207', $delivery->to_address);
        $this->assertSame('wamid.REPLY1', $delivery->external_id);
        $this->assertSame('meta-whatsapp', Message::query()->findOrFail($delivery->message_id)->messageType->verb);
        Http::assertSent(fn (HttpRequest $request): bool => $request['to'] === '18095550207' && $request['text']['body'] === 'Hola Mundo');
    }

    public function testSetupSubscribesTheWabaToTheSharedCallbackAndReadsItBack(): void
    {
        $this->globalAgentTemplates();
        Queue::fake([SetupPeopleChannelsJob::class]);
        $receiverUrl = MetaInbound::callbackUrl();
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
        Queue::assertPushed(SetupPeopleChannelsJob::class, 1);
    }

    /**
     * Removing deletes the company's token, so the WABA has to be unsubscribed before that, while
     * Meta can still be reached with it.
     */
    public function testRemovingTheIntegrationUnsubscribesTheWabaWithTheCompanysToken(): void
    {
        $this->globalAgentTemplates();
        Queue::fake([SetupPeopleChannelsJob::class]);
        $subscribed = false;
        $this->fakeWaba($subscribed);
        $integrationCompany = $this->integrationCompany();
        $integrationCompany->forceFill(['region_id' => Regions::getDefault($this->currentCompany(), $this->currentApp())->getId()])->save();
        new EnsureCommunicationSetupAction($this->currentApp(), $integrationCompany)->execute();
        $this->assertTrue($subscribed);

        new RemoveIntegrationCompanyAction($integrationCompany, $this->currentApp())->execute();

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), self::WABA_ID . '/subscribed_apps')
            && $request->hasHeader('Authorization', 'Bearer company-token'));
        $this->assertFalse($subscribed);
        $this->assertSame(
            SharedRegistration::RELEASED,
            SharedRegistration::query()->where('provider', 'meta')->where('resource_key', self::WABA_ID)->value('status')
        );
        $this->assertNull($this->currentCompany()->get(ConfigurationEnum::ACCESS_TOKEN->value));
    }

    public function testEveryConnectedNumberGetsItsOwnConnectionAndClaim(): void
    {
        $this->globalAgentTemplates();
        Queue::fake([SetupPeopleChannelsJob::class]);
        $subscribed = false;
        $this->fakeWaba($subscribed);
        $holder = $this->agent('Service Sofia', ['whatsapp_config' => ['phone_number' => '+1 (809) 555-0200']]);

        $report = new EnsureCommunicationSetupAction($this->currentApp(), $this->twoNumbers())->execute();

        $connections = Connection::query()
            ->where('provider', 'meta')
            ->where('companies_id', $this->currentCompany()->getId())
            ->get()
            ->keyBy('account_key');

        $this->assertEqualsCanonicalizing([self::OUR_NUMBER, self::SECOND_NUMBER], $connections->keys()->all(), json_encode($report));
        $this->assertEquals(
            ['phone_number_id' => self::SECOND_PHONE_NUMBER_ID, 'waba_id' => self::WABA_ID, 'agent_id' => $holder->getId()],
            $connections[self::SECOND_NUMBER]->config
        );
        $this->assertSame(self::PHONE_NUMBER_ID, $connections[self::OUR_NUMBER]->config['phone_number_id']);

        foreach ($connections as $number => $connection) {
            $this->assertSame(SetupStatusEnum::READY, $connection->setup_status, $number . ': ' . $connection->last_error);
            $this->assertSame(
                $connection->getId(),
                InboundClaim::query()->where('provider', 'meta')->where('inbound_identity', $number)->value('connection_id')
            );
        }

        $this->assertSame(
            $connections[self::OUR_NUMBER]->getId(),
            ChannelDefault::query()
                ->where('companies_id', $this->currentCompany()->getId())
                ->where('channel', 'whatsapp')
                ->value('connection_id'),
            'the first number the company connected is the one new messages leave from'
        );
        $this->assertSame(1, SharedRegistration::query()->where('provider', 'meta')->where('resource_key', self::WABA_ID)->count());
    }

    public function testANumberIsAnsweredByTheAgentHoldingItAndFromThatNumber(): void
    {
        $this->fakeGraph(['messages' => [['id' => 'wamid.REPLY-B']]]);
        $first = $this->metaConnectionOn(self::OUR_NUMBER, self::PHONE_NUMBER_ID, $this->agent(), default: true);
        $holder = $this->agent('Service Sofia', ['whatsapp_config' => ['phone_number' => self::SECOND_NUMBER]]);
        $second = $this->metaConnectionOn(self::SECOND_NUMBER, self::SECOND_PHONE_NUMBER_ID, $holder);

        $response = $this->answer($this->deliver($this->inboundOnSecondNumber('wamid.IN-B', '18095550207', 'Do you service hybrids?')));

        $delivery = Delivery::query()->where('connection_id', $second->getId())->sole();

        $this->assertSame('Hola Mundo', $response['response'] ?? null, json_encode($response));
        $this->assertEquals(['type' => 'agent', 'id' => $holder->getId()], $delivery->request->sender);
        $this->assertSame(0, Delivery::query()->where('connection_id', $first->getId())->count());
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/' . self::SECOND_PHONE_NUMBER_ID . '/messages')
            && $request['to'] === '18095550207');
    }

    public function testANumberNoAgentHoldsIsFiledAndNotAnswered(): void
    {
        Http::fake();
        $this->metaConnectionOn(self::OUR_NUMBER, self::PHONE_NUMBER_ID, $this->agent(), default: true);
        $second = $this->metaConnectionOn(self::SECOND_NUMBER, self::SECOND_PHONE_NUMBER_ID);

        $result = $this->deliver($this->inboundOnSecondNumber('wamid.IN-U', '18095550207', 'Anyone there?'));

        $this->assertNotNull(Message::query()->find($result[0]['message_id']));
        $this->assertNull($this->answer($result), 'the company\'s other agent does not answer for a number it does not hold');
        $this->assertSame(0, Delivery::query()->where('connection_id', $second->getId())->count());
        Http::assertNothingSent();
    }

    public function testAnAgentsOwnMessageLeavesFromTheNumberItHolds(): void
    {
        $this->fakeGraph(['messages' => [['id' => 'wamid.OUT-B']]]);
        $holder = $this->agent('Service Sofia', ['whatsapp_config' => ['phone_number' => self::SECOND_NUMBER]]);
        $first = $this->metaConnection(default: true);
        $second = $this->metaConnectionOn(self::SECOND_NUMBER, self::SECOND_PHONE_NUMBER_ID, $holder);
        $person = $this->personWithCellphone('+18095550208');

        $held = $this->communication()->send(
            $person,
            'Your car is ready',
            ['whatsapp'],
            from: Sender::agent($holder),
            sync: true
        );
        $company = $this->communication()->send(
            $person,
            'Thanks for visiting',
            ['whatsapp'],
            sync: true
        );

        $this->assertSame($second->getId(), $held->delivery(ChannelTypeEnum::WHATSAPP)->connection_id);
        $this->assertSame($first->getId(), $company->delivery(ChannelTypeEnum::WHATSAPP)->connection_id, 'nobody speaking: the company default');
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/' . self::SECOND_PHONE_NUMBER_ID . '/messages')
            && $request['text']['body'] === 'Your car is ready');
    }

    public function testRemovingTheIntegrationReleasesEveryNumber(): void
    {
        $this->globalAgentTemplates();
        Queue::fake([SetupPeopleChannelsJob::class]);
        $subscribed = false;
        $this->fakeWaba($subscribed);
        $integrationCompany = $this->twoNumbers();
        $integrationCompany->forceFill(['region_id' => Regions::getDefault($this->currentCompany(), $this->currentApp())->getId()])->save();
        new EnsureCommunicationSetupAction($this->currentApp(), $integrationCompany)->execute();
        $this->assertTrue($subscribed);

        new RemoveIntegrationCompanyAction($integrationCompany, $this->currentApp())->execute();

        $this->assertSame(
            0,
            Connection::query()->live()->where('integrations_company_id', $integrationCompany->getId())->count(),
            'both numbers are retired'
        );
        $this->assertSame(2, Connection::query()->where('integrations_company_id', $integrationCompany->getId())->count());
        $this->assertFalse($subscribed, 'the WABA they share is unsubscribed once');
        $this->assertCount(1, Http::recorded(fn (HttpRequest $request): bool => $request->method() === 'DELETE'));
        $this->assertSame(
            0,
            ChannelDefault::query()->where('companies_id', $this->currentCompany()->getId())->where('channel', 'whatsapp')->count()
        );
        $this->assertNull($this->currentCompany()->get(ConfigurationEnum::ACCESS_TOKEN->value));
    }

    public function testAnAgentChangingOrGivingUpItsNumberMovesTheConnectionWithIt(): void
    {
        Http::fake();
        $integrationCompany = $this->twoNumbers();
        new EnsureCommunicationSetupAction($this->currentApp(), $integrationCompany)->reconcile(MetaWhatsAppHandler::class);
        $holder = $this->agent('Service Sofia');
        $update = fn (?array $whatsappConfig) => $this->graphQL(
            'mutation($input: AgentAiInput!) { updateAiAgent(id: ' . $holder->getId() . ', input: $input) { id whatsapp_config } }',
            ['input' => [
                'agent_type_id' => $holder->agent_type_id,
                'name' => $holder->name,
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
                'whatsapp_config' => $whatsappConfig,
            ]]
        );

        $taken = $update(['phone_number' => '+1 (809) 555-0200']);
        $this->assertNull($taken->json('errors'), $taken->getContent());
        $this->assertSame([self::SECOND_NUMBER => $holder->getId()], $this->holders($integrationCompany));
        Queue::assertPushed(EnsureCommunicationSetupJob::class);

        $update(['phone_number' => self::OUR_NUMBER]);
        $this->assertSame([self::OUR_NUMBER => $holder->getId()], $this->holders($integrationCompany), 'the number it left names nobody');

        $update(null);
        $this->assertSame([], $this->holders($integrationCompany));
    }

    public function testAnAgentOfAnotherCompanyHoldingTheSameNumberNeverAnswersIt(): void
    {
        Http::fake();
        $outsider = Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->otherCompany()->getId())
            ->create(['name' => 'Outsider', 'whatsapp_config' => ['phone_number' => self::SECOND_NUMBER]]);
        $integrationCompany = $this->twoNumbers();
        new EnsureCommunicationSetupAction($this->currentApp(), $integrationCompany)->reconcile(MetaWhatsAppHandler::class);

        $this->assertSame([], $this->holders($integrationCompany), 'setup reads only the integration\'s own company');

        // Even a connection that names it: the holder is looked up among the connection's own company.
        $forged = $this->metaConnectionOn('+18095550300', '106540352242944', $outsider);
        $this->assertNull($forged->agent());

        $payload = $this->inboundPayload('wamid.IN-X', '18095550207', 'Hello?');
        $payload['entry'][0]['changes'][0]['value']['metadata'] = [
            'display_phone_number' => '18095550300',
            'phone_number_id' => '106540352242944',
        ];

        $this->assertNull($this->answer($this->deliver($payload)));
        $this->assertSame(0, Delivery::query()->where('connection_id', $forged->getId())->count());
        Http::assertNothingSent();
    }

    /**
     * Production today: the number lives in the company's settings, the row keeps nothing, and the
     * connection and its claim were made from that.
     */
    public function testACompanySetUpWithOneNumberKeepsItsConnectionAndClaimThroughEverySave(): void
    {
        $subscribed = false;
        $this->fakeWaba($subscribed);
        $legacy = IntegrationsCompany::create([
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $this->currentCompany()->getId(),
            'integrations_id' => $this->metaIntegration()->getId(),
            'region_id' => Regions::getDefault($this->currentCompany(), $this->currentApp())->getId(),
            'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
        ]);
        new EnsureCommunicationSetupAction($this->currentApp(), $legacy)->reconcile(MetaWhatsAppHandler::class);
        $connection = Connection::query()->where('integrations_company_id', $legacy->getId())->sole();
        $claimId = InboundClaim::query()->where('connection_id', $connection->getId())->sole()->getKey();
        $config = $connection->config;

        $resaved = $this->saveIntegration(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'company-token']);
        $this->assertSame((string) $legacy->getId(), (string) $resaved->json('data.integrationCompany.id'), $resaved->getContent());
        $this->assertSame([self::OUR_NUMBER => $connection->getId()], $this->liveNumbers());

        $added = $this->saveIntegration(['phone_number_id' => self::SECOND_PHONE_NUMBER_ID]);
        $this->assertNull($added->json('errors'), $added->getContent());
        $numbers = $this->liveNumbers();

        $this->assertSame([self::OUR_NUMBER, self::SECOND_NUMBER], array_keys($numbers));
        $this->assertSame($connection->getId(), $numbers[self::OUR_NUMBER]);
        $this->assertEquals($config, $connection->fresh()->config);
        $this->assertSame(
            $claimId,
            InboundClaim::query()->where('provider', 'meta')->where('inbound_identity', self::OUR_NUMBER)->sole()->getKey()
        );
        $this->assertSame(self::PHONE_NUMBER_ID, (string) $this->currentCompany()->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
    }

    public function testSavingTheIntegrationAgainWithAnotherNumberKeepsTheFirst(): void
    {
        $subscribed = false;
        $this->fakeWaba($subscribed);

        $first = $this->saveIntegration(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'company-token']);
        $this->assertSame('active', $first->json('data.integrationCompany.status.slug'), $first->getContent());
        $afterFirst = $this->liveNumbers();
        $this->assertSame([self::OUR_NUMBER], array_keys($afterFirst));

        $second = $this->saveIntegration(['phone_number_id' => self::SECOND_PHONE_NUMBER_ID]);
        $this->assertSame($first->json('data.integrationCompany.id'), $second->json('data.integrationCompany.id'), $second->getContent());
        $afterSecond = $this->liveNumbers();
        $this->assertSame([self::OUR_NUMBER, self::SECOND_NUMBER], array_keys($afterSecond));
        $this->assertSame($afterFirst[self::OUR_NUMBER], $afterSecond[self::OUR_NUMBER], 'the first number keeps its connection');

        $onlySecond = $this->saveIntegration([
            'phone_number_id' => self::SECOND_PHONE_NUMBER_ID,
            'phone_number_ids' => [self::SECOND_PHONE_NUMBER_ID],
        ]);
        $this->assertNull($onlySecond->json('errors'), $onlySecond->getContent());
        $this->assertSame([self::SECOND_NUMBER], array_keys($this->liveNumbers()), 'a number left out of the selection is released');
    }

    public function testTheWabasNumbersAreListedWithTheOnesTheCompanyConnected(): void
    {
        $subscribed = false;
        $this->fakeWaba($subscribed);
        $this->metaConnection();

        $this->graphQL('
            query { metaWhatsAppPhoneNumbers { phone_number_id phone_number display_phone_number is_connected } }
        ')->assertJson(['data' => ['metaWhatsAppPhoneNumbers' => [
            [
                'phone_number_id' => self::PHONE_NUMBER_ID,
                'phone_number' => self::OUR_NUMBER,
                'display_phone_number' => '+1 809-555-0100',
                'is_connected' => true,
            ],
            [
                'phone_number_id' => self::SECOND_PHONE_NUMBER_ID,
                'phone_number' => self::SECOND_NUMBER,
                'display_phone_number' => '+1 809-555-0200',
                'is_connected' => false,
            ],
        ]]]);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/' . self::WABA_ID . '/phone_numbers')
            && $request->hasHeader('Authorization', 'Bearer company-token'));
    }

    public function testNoNumbersAreListedWhenMetaRefusesTheCompanysToken(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 401)]);
        Log::spy();

        $this->graphQL('query { metaWhatsAppPhoneNumbers { phone_number_id } }')
            ->assertJson(['data' => ['metaWhatsAppPhoneNumbers' => []]]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context['status'] === 401
                && $context['meta_error'] === 'Invalid OAuth access token.'
                && ! str_contains((string) json_encode($context), 'company-token'))
            ->once();
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
        return $this->metaConnectionOn(self::OUR_NUMBER, self::PHONE_NUMBER_ID, default: $default);
    }

    private function metaConnectionOn(
        string $number,
        string $phoneNumberId,
        ?Agent $holder = null,
        bool $default = false
    ): Connection {
        $connection = $this->connection('meta', $number, [
            'config' => array_filter([
                'phone_number_id' => $phoneNumberId,
                'waba_id' => self::WABA_ID,
                'agent_id' => $holder?->getId(),
            ]),
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

    /**
     * @param array<string, mixed>|null $config what the handler keeps on the row; null is a company
     *                                          set up before the row kept its numbers
     */
    private function integrationCompany(?array $config = null): IntegrationsCompany
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
            'config' => $config,
        ]);
    }

    /**
     * The integration under its real name, which is how the handler finds the company's row. Made
     * here rather than read: the seeded row is whatever the database last migrated to.
     */
    private function metaIntegration(): Integrations
    {
        return $this->metaIntegration ??= Integrations::create([
            'apps_id' => 0,
            'name' => IntegrationsEnum::META_WHATSAPP->value,
            'handler' => MetaWhatsAppHandler::class,
        ]);
    }

    /**
     * A save of the integration as the admin does it, on the company's WABA.
     */
    private function saveIntegration(array $config): TestResponse
    {
        return $this->graphQL(
            'mutation($input: IntegrationsCompaniesInput!) { integrationCompany(input: $input) { id status { slug } } }',
            ['input' => [
                'integration' => ['id' => $this->metaIntegration()->getId()],
                'company_id' => $this->currentCompany()->getId(),
                'region' => ['id' => Regions::getDefault($this->currentCompany(), $this->currentApp())->getId()],
                'config' => $config + ['waba_id' => self::WABA_ID],
            ]]
        );
    }

    /**
     * @return array<string, int> the company's live Meta connections, id by number
     */
    private function liveNumbers(): array
    {
        return Connection::query()
            ->live()
            ->where('provider', 'meta')
            ->where('companies_id', $this->currentCompany()->getId())
            ->orderBy('account_key')
            ->pluck('id', 'account_key')
            ->all();
    }

    /**
     * @return array<string, int> the agent each of the integration's numbers names, by number
     */
    private function holders(IntegrationsCompany $integrationCompany): array
    {
        return Connection::query()
            ->live()
            ->where('integrations_company_id', $integrationCompany->getId())
            ->get()
            ->filter(fn (Connection $connection): bool => isset($connection->config['agent_id']))
            ->mapWithKeys(fn (Connection $connection): array => [$connection->account_key => $connection->config['agent_id']])
            ->all();
    }

    private function twoNumbers(): IntegrationsCompany
    {
        return $this->integrationCompany([MetaWhatsAppHandler::SELECTION => [
            ['phone_number_id' => self::PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0100'],
            ['phone_number_id' => self::SECOND_PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0200'],
        ]]);
    }

    /**
     * Meta as setup and teardown see it: the WABA's subscription, kept in $subscribed, and its numbers.
     */
    private function fakeWaba(bool &$subscribed): void
    {
        $receiverUrl = MetaInbound::callbackUrl();

        Http::fake(function (HttpRequest $request) use (&$subscribed, $receiverUrl) {
            if (str_contains($request->url(), '/subscribed_apps')) {
                return match ($request->method()) {
                    'POST' => Http::response(['success' => $subscribed = true]),
                    'DELETE' => Http::response(['success' => ! ($subscribed = false)]),
                    default => Http::response(['data' => $subscribed ? [['override_callback_uri' => $receiverUrl]] : []]),
                };
            }

            return Http::response(['data' => [
                ['id' => self::PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0100'],
                ['id' => self::SECOND_PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0200'],
            ]]);
        });
    }

    /**
     * The inbound pipeline's own turn: null when no agent holds the number the message arrived on.
     */
    private function answer(array $result): ?array
    {
        return new RespondToInboundMessageAction(
            Channel::query()->findOrFail($result[0]['channel_id']),
            Message::query()->findOrFail($result[0]['message_id'])
        )->executeIfOwned();
    }

    private function receiver(): ReceiverWebhook
    {
        return new CommunicationReceivers()->ensure($this->currentApp(), $this->currentCompany(), 'meta');
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

    private function postToCallback(string $body, string $secret = self::SECRET): TestResponse
    {
        return $this->call(
            'POST',
            '/v1/receiver/meta',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, $secret),
            ],
            content: $body
        );
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

    private function inboundFromUserId(string $id, string $userId, string $text): array
    {
        return $this->payload([
            'contacts' => [['profile' => ['name' => 'Beno Suriano Siu', 'username' => 'beno7surianosiu'], 'user_id' => $userId]],
            'messages' => [['from_user_id' => $userId, 'id' => $id, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => $text]]],
        ]);
    }

    /**
     * @param array<string, mixed> $contact what Meta says about the sender; empty for none at all
     */
    private function inboundFrom(string $id, string $from, array $contact): array
    {
        $payload = $this->inboundPayload($id, $from, 'Hola');
        $payload['entry'][0]['changes'][0]['value']['contacts'] = $contact === []
            ? []
            : [$contact + ['wa_id' => $from]];

        return $payload;
    }

    private function personBehind(array $result): People
    {
        $channel = Channel::query()->findOrFail($result[0]['channel_id']);

        return People::query()->findOrFail($channel->entity_id);
    }

    private function statusPayload(string $id, string $status, int $timestamp): array
    {
        return $this->payload([
            'statuses' => [['id' => $id, 'status' => $status, 'timestamp' => (string) $timestamp, 'recipient_id' => '18095550205']],
        ]);
    }

    private function inboundOnSecondNumber(string $id, string $from, string $text): array
    {
        $payload = $this->inboundPayload($id, $from, $text);
        $payload['entry'][0]['changes'][0]['value']['metadata'] = [
            'display_phone_number' => ltrim(self::SECOND_NUMBER, '+'),
            'phone_number_id' => self::SECOND_PHONE_NUMBER_ID,
        ];

        return $payload;
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

    private function agent(string $name = 'Sofia', array $attributes = []): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($this->currentApp()->getId())
            ->create(['name' => 'Sales (Neuron Test)', 'provider' => 'neuron', 'handler' => SalesNeuronAgentStub::class]);

        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create($attributes + [
                'name' => $name,
                'agent_type_id' => $agentType->getId(),
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);
    }
}
