<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\RespondIO;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\Actions\RespondToInboundMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Connectors\RespondIO\Actions\RetireLegacyRespondIOInboundAction;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Connectors\RespondIO\Handlers\RespondIOHandler;
use Kanvas\Connectors\RespondIO\RespondIOInbound;
use Kanvas\Connectors\RespondIO\RespondIOProvisioner;
use Kanvas\Connectors\RespondIO\WhatsAppTransport;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Guild\Leads\Actions\Communication\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Concerns\BuildsLegacyInboundFixtures;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

/**
 * Respond.io on the shared pipeline: its messages arrive on the one webhook job and are answered
 * by the one responder, through the workspace's connection.
 */
final class RespondIOProviderTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use BuildsLegacyInboundFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string CONTACT = '+60123450001';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['communication.providers' => [RespondIOHandler::class], 'queue.default' => 'sync']);

        $company = $this->currentCompany();
        $company->set(ConfigurationEnum::BEARER_TOKEN->value, 'respondio-test-token');
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());

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
        foreach ([ConfigurationEnum::BEARER_TOKEN, ConfigurationEnum::PHONE_NUMBER, ConfigurationEnum::WEBHOOK_SIGNING_KEY, ConfigurationEnum::ENABLED] as $key) {
            $this->currentCompany()->del($key->value);
        }

        parent::tearDown();
    }

    public function testRespondIoIsRegisteredAndCarriesWhatsApp(): void
    {
        $registry = new ProviderRegistry();

        $this->assertSame(RespondIOHandler::class, $registry->get('respondio'));
        $this->assertSame([ChannelTypeEnum::WHATSAPP->value => WhatsAppTransport::class], RespondIOHandler::transports());
        $this->assertFalse(RespondIOHandler::onboardable());
    }

    public function testTheWorkspaceIsOneAccountKeyedByItsNumberOnceTheTenantStatesIt(): void
    {
        $integration = $this->integrationRow();
        $placeholder = RespondIOHandler::accounts($integration)[0]->key;

        $this->assertSame($placeholder, ChannelTypeEnum::WHATSAPP->normalizeAddress($placeholder), 'an inbound identity is matched in this shape');
        $this->assertCount(1, RespondIOHandler::accounts($integration));

        $this->currentCompany()->set(ConfigurationEnum::PHONE_NUMBER->value, '+18095550111');

        $this->assertSame('+18095550111', RespondIOHandler::accounts($integration)[0]->key);
    }

    /**
     * A workspace that has not stated its number is keyed by its app, so a row saved before
     * integrations recorded theirs names no account and waits for an operator.
     */
    public function testALegacyRowWithoutAnAppNamesNoAccounts(): void
    {
        $integration = new IntegrationsCompany(['companies_id' => $this->currentCompany()->getId()]);
        $integration->setRelation('company', $this->currentCompany());
        $integration->setRelation('app', null);

        $this->assertSame([], RespondIOHandler::accounts($integration));
    }

    public function testTheAgentWhoseWhatsAppNumberIsTheWorkspacesAnswersItBeforeTheOneCarriedOver(): void
    {
        $carriedOver = $this->agent();
        $integration = $this->integrationRow(['agent_id' => $carriedOver->getId()]);

        $this->assertSame($carriedOver->getId(), RespondIOHandler::accounts($integration)[0]->attributes['agent_id']);

        $this->currentCompany()->set(ConfigurationEnum::PHONE_NUMBER->value, '+18095550111');
        $owner = $this->agent(['whatsapp_config' => ['phone_number' => '+1 809 555 0111']]);

        $this->assertSame($owner->getId(), RespondIOHandler::accounts($integration)[0]->attributes['agent_id']);
    }

    public function testAWorkspaceWithASigningKeyOnlyAcceptsRequestsSignedWithIt(): void
    {
        $receiver = $this->receiver();
        $inbound = new RespondIOInbound();
        $body = json_encode($this->inboundPayload(1001, 'Hi'));

        $this->assertTrue($inbound->authenticate($this->request($receiver, $body), $receiver));

        $this->currentCompany()->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'signing-key');

        $this->assertFalse($inbound->authenticate($this->request($receiver, $body), $receiver));
        $this->assertFalse($inbound->authenticate($this->request($receiver, $body, 'not-the-signature'), $receiver));
        $this->assertTrue($inbound->authenticate(
            $this->request($receiver, $body, base64_encode(hash_hmac('sha256', $body, 'signing-key', true))),
            $receiver
        ));
    }

    public function testAnInboundMessageIsFiledOnTheConnectionAndTheContactsConversation(): void
    {
        $connection = $this->respondIoConnection();

        $result = $this->deliver($this->inboundPayload(1002, 'Is it still available?'));

        $message = Message::query()->findOrFail($result[0]['message_id']);
        $channel = Channel::query()->findOrFail($result[0]['channel_id']);

        $this->assertSame('Is it still available?', $message->message['content']);
        $this->assertSame('respondio-text', $message->messageType->verb);
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
        $this->assertSame('respondio-' . self::CONTACT, $channel->slug, 'the slug these conversations always had');
        $this->assertSame('message.received', $message->message['raw_data']['event_type']);
    }

    public function testARedeliveredMessageIsFiledOnce(): void
    {
        $this->respondIoConnection();

        $first = $this->deliver($this->inboundPayload(1003, 'Hello'));
        $second = $this->deliver($this->inboundPayload(1003, 'Hello'));

        $this->assertSame($first[0]['message_id'], $second[0]['message_id']);
    }

    public function testAContactWithNoPhoneIsNotFiled(): void
    {
        $this->respondIoConnection();
        $payload = $this->inboundPayload(1004, 'Hello from Messenger');
        unset($payload['contact']['phone']);

        $result = $this->deliver($payload);

        $this->assertSame('contact_has_no_phone', $result['result']['reason']);
    }

    public function testAFileTheContactSentIsAnAttachmentToDownload(): void
    {
        $wrapped = $this->inboundPayload(1005, '');
        $wrapped['message']['message'] = [
            'type' => 'attachment',
            'attachment' => ['type' => 'image', 'url' => 'https://cdn.respond.test/photo.jpg', 'description' => 'the dent'],
        ];
        $unknown = $this->inboundPayload(1006, '');
        $unknown['message']['message'] = ['type' => 'custom_payload', 'payload' => ['a' => 1]];

        $inbound = new RespondIOInbound();
        $parsed = $inbound->messages($wrapped, $this->receiver())[0];

        $this->assertSame('the dent', $parsed->text);
        $this->assertSame('respondio-image', $parsed->messageVerb);
        $this->assertSame('https://cdn.respond.test/photo.jpg', $parsed->attachments[0]->url);
        $this->assertSame([], $inbound->messages($unknown, $this->receiver())[0]->attachments);
    }

    public function testASendPutsTheFilesFirstAndIsKnownByTheTextThatClosesIt(): void
    {
        Http::fake(['api.respond.io/*' => Http::sequence()
            ->push(['contactId' => 1, 'messageId' => 501])
            ->push(['contactId' => 1, 'messageId' => 502]),
        ]);
        $this->respondIoConnection(default: true);

        $delivery = $this->communication()
            ->send(
                $this->personWithCellphone(self::CONTACT),
                Content::text('Your quote is ready')
                    ->withAttachments(['https://files.kanvas.test/quote.pdf']),
                channels: ['whatsapp'],
                sync: true
            )
            ->delivery(ChannelTypeEnum::WHATSAPP);

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame('502', $delivery->external_id);

        $sent = Http::recorded()->map(fn (array $pair): HttpRequest => $pair[0])->values();
        $this->assertStringEndsWith('/contact/phone:' . self::CONTACT . '/message', $sent[0]->url());
        $this->assertTrue($sent[0]->hasHeader('Authorization', 'Bearer respondio-test-token'));
        $this->assertSame(['type' => 'file', 'url' => 'https://files.kanvas.test/quote.pdf'], $sent[0]['message']['attachment']);
        $this->assertSame('Your quote is ready', $sent[1]['message']['text']);
    }

    public function testARefusalFromRespondIoFailsTheDelivery(): void
    {
        Http::fake(['api.respond.io/*' => Http::response(['message' => 'Contact not found'], 404)]);
        $this->respondIoConnection(default: true);

        $delivery = $this->communication()
            ->send(
                $this->personWithCellphone(self::CONTACT),
                'Following up',
                channels: ['whatsapp'],
                sync: true
            )
            ->delivery(ChannelTypeEnum::WHATSAPP);

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->status);
        $this->assertSame('rejected_by_provider', $delivery->error_code);
        $this->assertStringContainsString('Contact not found', (string) $delivery->error);
    }

    /**
     * Respond.io has no kind for it, so the file is dropped; with no text behind it nothing left,
     * and the delivery has to say so instead of ending with an outcome nobody knows.
     */
    public function testASendWhoseOnlyFileCannotBeCarriedFailsInsteadOfBeingLost(): void
    {
        Http::fake();
        $this->respondIoConnection(default: true);

        $delivery = $this->communication()
            ->send(
                $this->personWithCellphone(self::CONTACT),
                Content::text('')->withAttachments(['https://files.kanvas.test/model.unknownkind']),
                channels: ['whatsapp'],
                sync: true
            )
            ->delivery(ChannelTypeEnum::WHATSAPP);

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->status);
        $this->assertSame('attachment_unsupported', $delivery->error_code);
        Http::assertNothingSent();
    }

    /**
     * A send with a file is two Respond.io messages and the delivery is known by the last. The
     * file's own echo matches no delivery, and would be filed as something a person typed.
     */
    public function testThePartsOfASendThatLeftThroughTheApiAreNotFiledWhenTheyComeBack(): void
    {
        Http::fake(['api.respond.io/*' => Http::sequence()
            ->push(['contactId' => 1, 'messageId' => 651])
            ->push(['contactId' => 1, 'messageId' => 652]),
        ]);
        $this->respondIoConnection(default: true);
        $this->communication()->send(
            $this->personWithCellphone(self::CONTACT),
            Content::text('Your quote is ready')->withAttachments(['https://files.kanvas.test/quote.pdf']),
            channels: ['whatsapp'],
            sync: true
        );
        $filed = Message::query()->fromCompany($this->currentCompany()->getId())->count();

        $fileEcho = $this->outgoingPayload(651, '');
        $fileEcho['sender'] = ['type' => 'message', 'source' => 'api'];
        $fileEcho['message']['message'] = [
            'type' => 'attachment',
            'attachment' => ['type' => 'file', 'url' => 'https://files.kanvas.test/quote.pdf'],
        ];

        $this->deliver($fileEcho);

        $this->assertSame($filed, Message::query()->fromCompany($this->currentCompany()->getId())->count());
    }

    public function testOurOwnSendComingBackUpdatesTheLedgerAndIsNotFiledAgain(): void
    {
        Http::fake(['api.respond.io/*' => Http::response(['contactId' => 1, 'messageId' => 601])]);
        $this->respondIoConnection(default: true);
        $delivery = $this->communication()
            ->send(
                $this->personWithCellphone(self::CONTACT),
                'Your quote is ready',
                channels: ['whatsapp'],
                sync: true
            )
            ->delivery(ChannelTypeEnum::WHATSAPP);

        $echo = $this->outgoingPayload(601, 'Your quote is ready', status: 'delivered');

        $this->assertSame(['recorded' => 1, 'duplicates' => 0, 'unmatched' => 0], $this->deliver($echo));
        $this->assertSame(['recorded' => 0, 'duplicates' => 1, 'unmatched' => 0], $this->deliver($echo));
        $this->assertSame(DeliveryStatusEnum::DELIVERED, $delivery->fresh()->status);
    }

    public function testAMessageSomeoneTypedInRespondIoIsFiledAsOurs(): void
    {
        $this->respondIoConnection();

        $result = $this->deliver($this->outgoingPayload(602, 'Let me check that for you'));

        $message = Message::query()->findOrFail($result[0]['message_id']);

        $this->assertTrue($result[0]['is_from_me']);
        $this->assertTrue($message->message['from_me']);
        $this->assertSame('respondio-' . self::CONTACT, Channel::query()->findOrFail($result[0]['channel_id'])->slug);
    }

    public function testTheResponderAnswersOnTheWorkspaceTheMessageArrivedOn(): void
    {
        Http::fake(['api.respond.io/*' => Http::response(['contactId' => 1, 'messageId' => 701])]);
        $connection = $this->respondIoConnection();
        $result = $this->deliver($this->inboundPayload(1007, 'Is it still available?'));
        $connection->forceFill(['config' => ['agent_id' => $this->agent()->getId()]])->saveOrFail();

        $response = new RespondToInboundMessageAction(
            Channel::query()->findOrFail($result[0]['channel_id']),
            Message::query()->findOrFail($result[0]['message_id'])
        )->execute();

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();

        $this->assertSame('Hola Mundo', $response['response'] ?? null);
        $this->assertSame(self::CONTACT, $delivery->to_address);
        $this->assertSame('701', $delivery->external_id);
        $this->assertSame('respondio-text', Message::query()->findOrFail($delivery->message_id)->messageType->verb);
        Http::assertSent(fn (HttpRequest $request): bool => $request['message']['text'] === 'Hola Mundo');
    }

    public function testALeadIsMessagedThroughTheWorkspacesConnection(): void
    {
        Http::fake(['api.respond.io/*' => Http::response(['contactId' => 1, 'messageId' => 801])]);
        $connection = $this->respondIoConnection();
        $this->currentCompany()->set(ConfigurationEnum::ENABLED->value, true);
        $lead = $this->leadOf($this->personWithCellphone(self::CONTACT));

        $result = new SendMessageToLeadAction($lead)->execute(channel: 'whatsapp', message: 'Your car is ready');

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();

        $this->assertSame('respondio', $result['channel']);
        $this->assertSame('801', $result['provider_message_id']);
        $this->assertSame($delivery->request_id, $result['operation_id']);
        $this->assertSame(self::CONTACT, $delivery->to_address);
    }

    public function testALeadsFilesGoBeforeTheTextAndVideoLinksCloseIt(): void
    {
        Http::fake(['api.respond.io/*' => Http::sequence()
            ->push(['contactId' => 1, 'messageId' => 811])
            ->push(['contactId' => 1, 'messageId' => 812])
            ->push(['contactId' => 1, 'messageId' => 813]),
        ]);
        $this->respondIoConnection();
        $this->currentCompany()->set(ConfigurationEnum::ENABLED->value, true);
        $lead = $this->leadOf($this->personWithCellphone(self::CONTACT));
        $action = new class ($lead) extends SendMessageToLeadAction {
            /**
             * @param list<array<string, mixed>> $files
             * @param list<array<string, mixed>> $videoEngagements
             */
            public function sendThroughRespondIo(string $message, array $files, array $videoEngagements): array
            {
                $this->processedFiles = $files;
                $this->videoEngagements = $videoEngagements;

                return $this->sendWhatsAppMessage($message);
            }
        };

        $result = $action->sendThroughRespondIo(
            'final body',
            [
                ['type' => MediaTypeEnum::IMAGE, 'url' => 'https://cdn.example.com/photo.jpg'],
                ['type' => MediaTypeEnum::VIDEO, 'url' => 'https://cdn.example.com/raw.mp4', 'is_processed_video' => true],
                ['type' => MediaTypeEnum::DOCUMENT, 'url' => 'https://cdn.example.com/quote.pdf'],
            ],
            [['url' => 'https://watch.example.com/v/1']]
        );

        $sent = Http::recorded()->map(fn (array $pair): HttpRequest => $pair[0])->values();

        $this->assertSame('813', $result['provider_message_id']);
        $this->assertCount(3, $sent, 'the processed video rides as its link, never as a file');
        $this->assertSame(['type' => 'image', 'url' => 'https://cdn.example.com/photo.jpg'], $sent[0]['message']['attachment']);
        $this->assertSame(['type' => 'file', 'url' => 'https://cdn.example.com/quote.pdf'], $sent[1]['message']['attachment']);
        $this->assertSame("final body\n\nhttps://watch.example.com/v/1", $sent[2]['message']['text']);
    }

    public function testALeadWithNoCellphoneIsNotSentThroughRespondIo(): void
    {
        Http::fake();
        $this->respondIoConnection();
        $this->currentCompany()->set(ConfigurationEnum::ENABLED->value, true);
        $people = $this->personWithCellphone(self::CONTACT);
        $people->contacts()->delete();

        $result = new SendMessageToLeadAction($this->leadOf($people))->execute(channel: 'whatsapp', message: 'Hello');

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['classification']);
        Http::assertNothingSent();
    }

    public function testAWorkspaceWithNoConnectionFailsTheLeadSendWithoutCallingRespondIo(): void
    {
        Http::fake();
        $this->currentCompany()->set(ConfigurationEnum::ENABLED->value, true);
        $lead = $this->leadOf($this->personWithCellphone(self::CONTACT));

        $result = new SendMessageToLeadAction($lead)->execute(channel: 'whatsapp', message: 'Your car is ready');

        $this->assertFalse($result['success']);
        $this->assertSame('respondio', $result['channel']);
        Http::assertNothingSent();
    }

    public function testTheProbeReadsTheStoredToken(): void
    {
        $integration = $this->integrationRow();
        $provisioner = new RespondIOProvisioner();

        Http::fake(['api.respond.io/v2/space/user' => Http::response([], 401)]);
        $this->assertSame('Respond.io rejected the stored bearer token.', $provisioner->probe($integration, $this->currentApp()));

        $this->currentCompany()->del(ConfigurationEnum::BEARER_TOKEN->value);
        $appToken = $this->currentApp()->get(ConfigurationEnum::BEARER_TOKEN->value);

        if ($appToken === null) {
            $this->assertSame('The Respond.io bearer token is not set.', $provisioner->probe($integration, $this->currentApp()));
        }
    }

    public function testTheWorkspacesOldReceiverBecomesTheCommunicationReceiverAndItsRulesAgentKeepsAnswering(): void
    {
        $agent = $this->agent();
        $rule = $this->legacyResponderRule(RetireLegacyRespondIOInboundAction::RESPONDER_ACTIVITY, $agent);
        $legacy = $this->legacyReceiver(RetireLegacyRespondIOInboundAction::RECEIVER_JOB, ['pipeline_id' => 7]);
        $connection = $this->respondIoConnection();
        $spare = $this->receiver();

        $retired = new RetireLegacyRespondIOInboundAction($connection)->execute();

        $adopted = new CommunicationReceivers()->find($this->currentApp(), $this->currentCompany(), 'respondio');

        $this->assertSame($legacy->uuid, $adopted->uuid, 'the URL in Respond.io keeps working');
        $this->assertSame(7, $adopted->configuration['pipeline_id'], 'what the receiver was configured with stays');
        $this->assertTrue((bool) $spare->fresh()->is_deleted);
        $this->assertTrue((bool) $rule->fresh()->is_deleted);
        $this->assertSame($agent->getId(), $connection->fresh()->agent()?->getId());
        $this->assertSame(
            $agent->getId(),
            IntegrationsCompany::query()->findOrFail($connection->integrations_company_id)->config['agent_id']
        );
        $this->assertContains('rule ' . $rule->name, $retired);
        $this->assertSame([], new RetireLegacyRespondIOInboundAction(Connection::query()->findOrFail($connection->getId()))->execute());
    }

    public function testAWorkspaceAnAgentAlreadyAnswersIsNotHandedToTheRulesAgent(): void
    {
        $owner = $this->agent();
        $this->legacyResponderRule(RetireLegacyRespondIOInboundAction::RESPONDER_ACTIVITY, $this->agent());
        $connection = $this->respondIoConnection();
        $connection->forceFill(['config' => ['agent_id' => $owner->getId()]])->saveOrFail();

        new RetireLegacyRespondIOInboundAction($connection)->execute();

        $this->assertSame($owner->getId(), $connection->fresh()->agent()?->getId());
    }

    private function respondIoConnection(bool $default = false): Connection
    {
        $connection = $this->connection(
            RespondIOHandler::slug(),
            RespondIOHandler::accountKey($this->currentApp(), $this->currentCompany())
        );
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
     * @param array<string, mixed> $config
     */
    private function integrationRow(array $config = []): IntegrationsCompany
    {
        $integration = new IntegrationsCompany([
            'companies_id' => $this->currentCompany()->getId(),
            'apps_id' => $this->currentApp()->getId(),
            'config' => $config,
        ]);
        $integration->setRelation('company', $this->currentCompany());
        $integration->setRelation('app', $this->currentApp());

        return $integration;
    }

    private function receiver(): ReceiverWebhook
    {
        return new CommunicationReceivers()->ensure($this->currentApp(), $this->currentCompany(), 'respondio');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function agent(array $attributes = []): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($this->currentApp()->getId())
            ->create(['name' => 'Sales (Neuron Test)', 'provider' => 'neuron', 'handler' => SalesNeuronAgentStub::class]);

        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create($attributes + [
                'name' => 'Sofia',
                'agent_type_id' => $agentType->getId(),
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
                'whatsapp_config' => null,
            ]);
    }

    private function request(ReceiverWebhook $receiver, string $body, ?string $signature = null): Request
    {
        $request = Request::create(
            $receiver->getUrl(),
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body
        );

        if ($signature !== null) {
            $request->headers->set('X-Webhook-Signature', $signature);
        }

        return $request;
    }

    private function deliver(array $payload): array
    {
        $receiver = $this->receiver();
        $call = new ProcessWebhookAttemptAction($receiver, $this->request($receiver, json_encode($payload)))->execute();

        return new ProcessCommunicationWebhookJob($call)->handle();
    }

    private function inboundPayload(int $messageId, string $text): array
    {
        return [
            'sender' => ['type' => 'message', 'source' => 'contact'],
            'channel' => ['id' => 1, 'name' => 'WhatsApp', 'source' => 'whatsapp_business'],
            'contact' => ['id' => 1, 'phone' => self::CONTACT, 'firstName' => 'Ana', 'lastName' => 'Lopez'],
            'message' => [
                'message' => ['type' => 'text', 'text' => $text],
                'traffic' => 'incoming',
                'messageId' => $messageId,
                'timestamp' => 1700000000,
            ],
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'message.received',
        ];
    }

    private function outgoingPayload(int $messageId, string $text, string $status = 'sent'): array
    {
        return [
            'sender' => ['type' => 'message', 'source' => 'user', 'userId' => 25262],
            'channel' => ['id' => 1, 'name' => 'WhatsApp', 'source' => 'whatsapp_business'],
            'contact' => ['id' => 1, 'phone' => self::CONTACT, 'firstName' => 'Ana', 'lastName' => 'Lopez'],
            'message' => [
                'status' => [['value' => 'pending', 'timestamp' => 1700000000], ['value' => $status, 'timestamp' => 1700000100]],
                'message' => ['type' => 'text', 'text' => $text],
                'traffic' => 'outgoing',
                'messageId' => $messageId,
                'timestamp' => 1700000000,
            ],
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'message.sent',
        ];
    }
}
