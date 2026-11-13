<?php

declare(strict_types=1);

namespace Tests\Communication\Channels\Email\Mailgun;

use Baka\Support\Str as BakaStr;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\EmailTransport;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Handlers\MailgunHandler;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\MailgunProvisioner;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Communication\Support\TransportManager;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
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

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

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

        // The app's own key wins over the company's when an operator set one on this environment.
        $appKey = (string) $this->currentApp()->get(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value);
        if ($appKey !== '') {
            $this->signingKey = $appKey;
        }

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

        $accounts = MailgunHandler::accounts($integration);

        $this->assertCount(1, $accounts);
        $this->assertSame(self::OUR_ADDRESS, $accounts[0]->key);
        $this->assertSame(['domain' => self::DOMAIN], $accounts[0]->attributes);

        $company->del(ConfigurationEnum::FROM_ADDRESS->value);
        $company->set('from_email_address', 'Hello@MG.kanvas.test');

        $this->assertSame('hello@mg.kanvas.test', MailgunHandler::accounts($integration)[0]->key);

        $company->set('from_email_address', 'hello@elsewhere.test');

        $this->assertSame([], MailgunHandler::accounts($integration));
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
        $this->assertSame('Ana Pérez', $person->firstname);
        $this->assertSame('ana@customer.test', $person->contacts()->where('contacts_types_id', ContactTypeEnum::EMAIL->value)->value('value'));
        $this->assertInstanceOf(Lead::class, $message->entity(Lead::class));
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

    public function testARefusalFromMailgunFailsTheDelivery(): void
    {
        $this->defaultEmailConnection();
        Http::fake(['api.mailgun.net/*' => Http::response(['message' => "'to' parameter is not a valid address"], 400)]);

        $delivery = $this->communication()
            ->send($this->personWithEmail('ana@customer.test'), 'Hello', channels: ['email'], sync: true)
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

    public function testADomainsEventsHeldByAnotherCompanysReceiverStayThere(): void
    {
        $vendor = $this->fakeMailgun();
        $theirs = new CommunicationReceivers()->ensure($this->currentApp(), $this->otherCompany(), 'mailgun')->getUrl();
        $vendor->webhooks = array_fill_keys(MailgunProvisioner::EVENTS, $theirs);
        $connection = $this->connection('mailgun', self::OUR_ADDRESS, ['config' => ['domain' => self::DOMAIN]]);

        $registration = new MailgunProvisioner()->registerInbound($connection, self::OURS);

        $this->assertSame(array_fill_keys(MailgunProvisioner::EVENTS, $theirs), $vendor->webhooks);
        $this->assertSame('forward("' . self::OURS . '")', $vendor->routes['r-1']['actions'][0]);
        $this->assertSame(self::OURS, $registration->shared?->registered_value);
    }

    public function testADomainsEventsOnAnOldReceiverOfTheSameCompanyAreMoved(): void
    {
        $vendor = $this->fakeMailgun();
        $vendor->webhooks = array_fill_keys(MailgunProvisioner::EVENTS, 'https://kanvas.test/v1/receiver/retired');
        $connection = $this->connection('mailgun', self::OUR_ADDRESS, ['config' => ['domain' => self::DOMAIN]]);

        new MailgunProvisioner()->registerInbound($connection, self::OURS);

        $this->assertSame(array_fill_keys(MailgunProvisioner::EVENTS, self::OURS), $vendor->webhooks);
    }

    public function testAnOpenHeardOnAnotherCompanysReceiverIsRecordedForTheSendersCompany(): void
    {
        $delivery = $this->acceptedMailgunDelivery('20261112.1@mg.kanvas.test');
        $lead = $this->leadOf($this->personWithEmail('ana@customer.test'));
        $theirReceiver = new CommunicationReceivers()->ensure($this->currentApp(), $this->otherCompany(), 'mailgun');

        $result = $this->processCall(
            $this->eventPayload('opened', '<20261112.1@mg.kanvas.test>', ['user-variables' => ['lead_id' => (string) $lead->getId()]]),
            $theirReceiver
        );

        $this->assertSame($lead->getId(), $result['lead_id'] ?? null);
        $this->assertSame(DeliveryStatusEnum::READ, $delivery->fresh()->status);
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
    private function processCall(array $payload, ?ReceiverWebhook $receiver = null): array
    {
        $receiver ??= $this->receiver();

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
        };

        Http::fake(function (HttpRequest $request) use ($vendor) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();

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
