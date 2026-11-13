<?php

declare(strict_types=1);

namespace Tests\Communication\Channels\Sms\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Handlers\TwilioHandlers;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\SmsTransport;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\TwilioInbound;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Communication\Support\TransportManager;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Twilio on the shared pipeline: the one webhook job takes its texts and its delivery receipts, and
 * the company's number is read from where every Twilio send has always read it.
 */
final class TwilioProviderTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    private const string ACCOUNT_SID = 'ACtest0000000000000000000000000000';
    private const string AUTH_TOKEN = 'twilio-test-token';
    private const string OUR_NUMBER = '+18095550100';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['communication.providers' => [TwilioHandlers::class], 'queue.default' => 'sync']);

        $company = $this->currentCompany();
        $company->set(ConfigurationEnum::TWILIO_ACCOUNT_SID->value, self::ACCOUNT_SID);
        $company->set(ConfigurationEnum::TWILIO_AUTH_TOKEN->value, self::AUTH_TOKEN);
        $company->set(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, self::OUR_NUMBER);

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
        foreach ([
            ConfigurationEnum::TWILIO_ACCOUNT_SID,
            ConfigurationEnum::TWILIO_AUTH_TOKEN,
            ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER,
            ConfigurationEnum::TWILIO_MESSAGING_SERVICE_SID,
        ] as $key) {
            $this->currentCompany()->del($key->value);
        }

        parent::tearDown();
    }

    public function testTwilioIsRegisteredAndCarriesSmsAndMms(): void
    {
        $transports = new TransportManager();

        $this->assertSame(TwilioHandlers::class, new ProviderRegistry()->get('twilio'));
        $this->assertInstanceOf(SmsTransport::class, $transports->for('twilio', ChannelTypeEnum::SMS));
        $this->assertTrue($transports->supports('twilio', ChannelTypeEnum::MMS));
        $this->assertFalse($transports->supports('twilio', ChannelTypeEnum::EMAIL));
        $this->assertFalse(TwilioHandlers::sharesAppReceiver());
    }

    /**
     * The account is the number the company sends from, kept on the company like the credentials;
     * the integration row only records that the integration was done.
     */
    public function testTheAccountIsTheCompanysSendingNumber(): void
    {
        $company = $this->currentCompany();
        $integration = new IntegrationsCompany(['companies_id' => $company->getId()]);
        $integration->setRelation('company', $company);

        $accounts = TwilioHandlers::accounts($integration);

        $this->assertCount(1, $accounts);
        $this->assertSame(self::OUR_NUMBER, $accounts[0]->key);
        $this->assertTrue($accounts[0]->isDefault);
        $this->assertSame([], $accounts[0]->attributes);

        $company->set(ConfigurationEnum::TWILIO_MESSAGING_SERVICE_SID->value, 'MG123');

        $this->assertSame(['messaging_service_sid' => 'MG123'], TwilioHandlers::accounts($integration)[0]->attributes);

        $company->del(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value);

        $this->assertSame([], TwilioHandlers::accounts($integration));
    }

    public function testOnlyARequestSignedWithTheCompanysTokenIsAccepted(): void
    {
        $receiver = $this->receiver();
        $params = $this->inboundPayload('SM-sig', '+18095550201', 'Hola');

        $this->assertTrue(ProcessCommunicationWebhookJob::authenticateRequest($this->signedRequest($receiver, $params), $receiver));
        $this->assertFalse(ProcessCommunicationWebhookJob::authenticateRequest(
            $this->signedRequest($receiver, $params, token: 'someone-else'),
            $receiver
        ));
    }

    public function testAnInboundTextIsFiledOnTheConnectionAndTheNumbersConversation(): void
    {
        $connection = $this->connection('twilio', self::OUR_NUMBER);
        $this->claim($connection);

        $result = $this->deliver($this->inboundPayload('SM-in-1', '+18095550202', 'Is it still available?'));

        $message = Message::query()->findOrFail($result[0]['message_id']);
        $channel = Channel::query()->findOrFail($result[0]['channel_id']);
        $person = $message->entity(People::class);

        $this->assertSame('Is it still available?', $message->message['content']);
        $this->assertSame('twilio-sms', $message->messageType->verb);
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
        $this->assertSame('sms', $message->message['communication']['channel']);
        $this->assertSame('+18095550202', $message->message['communication']['thread']);
        $this->assertSame('twilio-8095550202', $channel->slug);
        $this->assertInstanceOf(People::class, $person);
        $this->assertSame('8095550202', (string) $person->get('twilio_jid'));
        $this->assertInstanceOf(Lead::class, $message->entity(Lead::class));
        $this->assertFalse($result[0]['automated_response_suppressed']);
    }

    public function testADeliveryReceiptUpdatesTheLedgerDeliveryOnce(): void
    {
        $delivery = $this->acceptedTwilioDelivery('SM-ledger-1');
        $receipt = ['MessageSid' => 'SM-ledger-1', 'MessageStatus' => 'delivered', 'AccountSid' => self::ACCOUNT_SID];

        $first = $this->processCall($receipt);
        $second = $this->processCall($receipt);

        $this->assertSame('Twilio status recorded on delivery', $first['message']);
        $this->assertSame('Twilio status already recorded on delivery', $second['message']);
        $this->assertSame(DeliveryStatusEnum::DELIVERED, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->events_closed_at);
        $this->assertSame(1, $delivery->events()->count());
    }

    public function testAFailedReceiptCarriesTwiliosErrorOntoTheDelivery(): void
    {
        $delivery = $this->acceptedTwilioDelivery('SM-ledger-2');

        $this->processCall([
            'MessageSid' => 'SM-ledger-2',
            'MessageStatus' => 'undelivered',
            'ErrorCode' => '30003',
            'ErrorMessage' => 'Unreachable destination handset',
            'To' => '+18095550199',
            'AccountSid' => self::ACCOUNT_SID,
        ]);

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->fresh()->status);
        $this->assertSame('30003', $delivery->fresh()->error_code);
    }

    public function testAReceiptForATextNotInTheLedgerIsIgnored(): void
    {
        $this->assertSame(
            'No outbound text on record for this MessageSid, ignored',
            $this->processCall(['MessageSid' => 'SM-unknown', 'MessageStatus' => 'delivered', 'AccountSid' => self::ACCOUNT_SID])['message']
        );
    }

    public function testTheStatusCallbackUrlPrefersTheTenantsCommunicationReceiver(): void
    {
        $this->assertNull(TwilioInbound::statusCallbackUrl($this->currentApp(), $this->currentCompany()));

        $legacy = $this->legacyStatusReceiver();

        $this->assertSame($legacy->getUrl(), TwilioInbound::statusCallbackUrl($this->currentApp(), $this->currentCompany()));

        $own = $this->receiver();

        $this->assertSame($own->getUrl(), TwilioInbound::statusCallbackUrl($this->currentApp(), $this->currentCompany()));
    }

    private function acceptedTwilioDelivery(string $sid): Delivery
    {
        $this->defaultSmsConnection('twilio', self::OUR_NUMBER);

        $delivery = $this->communication()
            ->send($this->personWithCellphone('+18095550199'), 'Hello', channels: ['sms'])
            ->delivery(ChannelTypeEnum::SMS);

        $delivery->forceFill([
            'status' => DeliveryStatusEnum::ACCEPTED,
            'external_id' => $sid,
            'accepted_at' => now(),
        ])->saveOrFail();

        return $delivery;
    }

    private function receiver(): ReceiverWebhook
    {
        return new CommunicationReceivers()->ensure($this->currentApp(), $this->currentCompany(), 'twilio');
    }

    private function legacyStatusReceiver(): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessTwilioMessageStatusWebhookJob::class],
            ['name' => 'ProcessTwilioMessageStatusWebhookJob'],
        );

        return ReceiverWebhook::factory()
            ->app($this->currentApp()->getId())
            ->company($this->currentCompany()->getId())
            ->user(auth()->user()->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
                'is_active' => true,
            ]);
    }

    private function signedRequest(ReceiverWebhook $receiver, array $params, string $token = self::AUTH_TOKEN): Request
    {
        $request = Request::create($receiver->getUrl(), 'POST', $params);
        $request->headers->set('X-Twilio-Signature', new RequestValidator($token)->computeSignature($receiver->getUrl(), $params));

        return $request;
    }

    private function deliver(array $params): array
    {
        $receiver = $this->receiver();
        $call = new ProcessWebhookAttemptAction($receiver, $this->signedRequest($receiver, $params))->execute();

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

    private function inboundPayload(string $sid, string $from, string $body): array
    {
        return [
            'AccountSid' => self::ACCOUNT_SID,
            'SmsMessageSid' => $sid,
            'MessageSid' => $sid,
            'From' => $from,
            'To' => self::OUR_NUMBER,
            'Body' => $body,
            'NumMedia' => '0',
        ];
    }
}
