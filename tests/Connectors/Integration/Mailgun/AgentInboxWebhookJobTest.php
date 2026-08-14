<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Webhooks\AgentInboxWebhookJob;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

final class AgentInboxWebhookJobTest extends TestCase
{
    private const string DOMAIN = 'agents.kanvas.test';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private Agent $agent;
    private ReceiverWebhook $receiver;
    private string $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $this->kanvasApp->set(ConfigurationEnum::API_KEY->value, 'key-test');
        $this->company->set(ConfigurationEnum::DOMAIN->value, self::DOMAIN);
        $this->company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $this->user->getId());
        // Company settings survive a rollback: a leaked APPROVAL from another suite would lock the
        // reply as a draft and never send it.
        $this->company->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::FULL_ON->value);

        $agentType = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        $this->agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'name' => 'Sofia ' . Str::random(5),
                'user_id' => $this->user->getId(),
                'agent_type_id' => $agentType->getId(),
            ]);

        $this->mailbox = $this->agent->slug . '@' . self::DOMAIN;
        $this->agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $this->mailbox);
        $this->agent->set(CustomFieldEnum::MAILBOX_ACCESS->value, MailboxAccessEnum::RESTRICTED->value);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => AgentInboxWebhookJob::class],
            ['name' => 'AgentInboxWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($this->user->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    ReceiverConfigurationEnum::AGENT_ID->value => $this->agent->getId(),
                    ReceiverConfigurationEnum::MAILBOX_ADDRESS->value => $this->mailbox,
                ],
            ]);
    }

    /**
     * App/company config outlives the rollback, and a configured Mailgun is what makes
     * CreateAgentAction auto-provision — leaving it set would have unrelated suites dispatching
     * mailbox jobs for every agent they create.
     */
    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::DOMAIN->value);
        $this->kanvasApp->del(ConfigurationEnum::API_KEY->value);

        parent::tearDown();
    }

    public function testATeammateEmailIsAnsweredFromTheAgentsOwnAddress(): void
    {
        $this->fakeMailgun();

        $result = $this->deliver([
            'sender' => $this->user->email,
            'from' => 'Max <' . $this->user->email . '>',
            'subject' => 'Where are we on the Acme deal?',
            'stripped-text' => 'Where are we on the Acme deal?',
        ]);

        $this->assertStringContainsString('Hola Mundo', (string) ($result['response'] ?? ''));

        $inbound = Message::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->whereJsonContains('message->from_email', $this->user->email)
            ->latest('id')
            ->first();

        $this->assertNotNull($inbound);
        // A teammate writing in is the entity — same shape as a Slack DM, and what lets the agent
        // read who it is talking to.
        $this->assertInstanceOf(Users::class, $inbound->entity());
        $this->assertSame($this->user->getId(), $inbound->entity()->getId());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/' . self::DOMAIN . '/messages')
            && str_contains((string) $request->body(), $this->mailbox)
            && str_contains((string) $request->body(), 'Re: Where are we on the Acme deal?'));
    }

    public function testTheReplyCarriesThreadingHeadersSoItLandsInTheSameThread(): void
    {
        $this->fakeMailgun();
        // Randomized: the delivery dedupe cache outlives the test run, so a fixed id makes this
        // pass alone and fail the moment the file is run twice inside the TTL.
        $messageId = '<thread-' . Str::random(8) . '@mail.gmail.test>';

        $this->deliver([
            'sender' => $this->user->email,
            'subject' => 'Status',
            'stripped-text' => 'Any news?',
            'Message-Id' => $messageId,
        ]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/messages')
            && str_contains((string) $request->body(), 'h:In-Reply-To')
            && str_contains((string) $request->body(), $messageId));
    }

    public function testAStrangerIsTurnedAwayWhenTheMailboxIsRestricted(): void
    {
        $this->fakeMailgun();

        $result = $this->deliver([
            'sender' => 'stranger-' . Str::random(6) . '@outside.test',
            'stripped-text' => 'hi',
        ]);

        $this->assertStringContainsString('not known to this company', $result['message']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/messages'));
    }

    public function testAnOpenMailboxCapturesAnUnknownSenderAsAContact(): void
    {
        $this->fakeMailgun();
        $this->agent->set(CustomFieldEnum::MAILBOX_ACCESS->value, MailboxAccessEnum::OPEN->value);
        $stranger = 'lead-' . Str::random(6) . '@outside.test';

        $this->deliver([
            'sender' => $stranger,
            'from' => 'Jane Prospect <' . $stranger . '>',
            'subject' => 'Pricing',
            'stripped-text' => 'What does it cost?',
        ]);

        $people = PeoplesRepository::getByEmail($stranger, $this->company, $this->kanvasApp);
        $this->assertNotNull($people, 'An open mailbox is an inbound funnel — the sender becomes a contact.');
        $this->assertSame('Jane Prospect', $people->name);
    }

    public function testTheAgentsOwnMailIsIgnored(): void
    {
        $this->fakeMailgun();

        $result = $this->deliver([
            'sender' => $this->mailbox,
            'stripped-text' => 'my own reply echoed back',
        ]);

        // Without this the agent answers itself, forever, on the customer's Mailgun bill.
        $this->assertSame('Message from the agent itself, ignored', $result['message']);
    }

    public function testAnAutoReplyIsIgnored(): void
    {
        $this->fakeMailgun();

        $result = $this->deliver([
            'sender' => $this->user->email,
            'stripped-text' => 'I am out of the office until Monday.',
            'Auto-Submitted' => 'auto-replied',
        ]);

        $this->assertSame('Auto-reply ignored', $result['message']);
    }

    public function testTheSameMessageIdIsOnlyAnsweredOnce(): void
    {
        $this->fakeMailgun();
        $messageId = '<dupe-' . Str::random(6) . '@mail.test>';

        $payload = [
            'sender' => $this->user->email,
            'subject' => 'Ping',
            'stripped-text' => 'ping',
            'Message-Id' => $messageId,
        ];

        $this->deliver($payload);
        $result = $this->deliver($payload);

        // Mailgun retries a forward it doesn't see acked, and a reply-all with the address cc'd
        // delivers the same Message-Id twice.
        $this->assertSame('Duplicate delivery', $result['message']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function deliver(array $payload): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();
        $result = new AgentInboxWebhookJob($webhookRequest)->handle();

        if (! is_array($result)) {
            // ProcessWebhookJob swallows throwables into Sentry and returns null — without this the
            // real message is lost and the failure surfaces as an unrelated TypeError.
            $this->fail('Job failed: ' . json_encode($webhookRequest->refresh()->exception['message'] ?? 'unknown'));
        }

        return $result;
    }

    private function fakeMailgun(): void
    {
        Http::fake([
            'api.mailgun.net/v3/domains/*' => Http::response(['domain' => ['name' => self::DOMAIN]]),
            'api.mailgun.net/v3/*/messages' => Http::response(['id' => '<queued@' . self::DOMAIN . '>']),
            '*' => Http::response([]),
        ]);
    }
}
