<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mailgun\Actions\AgentChannelResponderAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\ApproveAgentMessageAction;
use Kanvas\Social\Messages\Actions\RejectAgentMessageAction;
use Kanvas\Social\Messages\Actions\RequestMessageApprovalAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Laravel\Ai\AnonymousAgent;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Throwable;

class AgentMessageApprovalTest extends TestCase
{
    use DatabaseTransactions;

    // ecosystem for the approval_requests/approval_policies rows, intelligence for the ledger event
    // every decision emits, social for the messages themselves.
    protected $connectionsToTransact = ['mysql', 'social', 'ecosystem', 'intelligence'];

    public function testCompanyApprovalModeLocksDraftAndSkipsSend(): void
    {
        Notification::fake();

        $outbound = $this->draftInApprovalMode();

        $this->assertNotNull($outbound);
        $this->assertSame(1, (int) $outbound->is_locked, 'Draft must be locked in APPROVAL mode');
        Notification::assertNothingSent();
    }

    public function testDefaultModeAutoSendsWithoutLocking(): void
    {
        Notification::fake();

        $outbound = $this->draftWithApprovalOff();

        $this->assertNotNull($outbound);
        $this->assertSame(0, (int) $outbound->is_locked, 'Draft must not be locked when approval is off');
        Notification::assertSentOnDemand(Blank::class);
    }

    public function testApproveSendsDraftAndUnlocks(): void
    {
        Notification::fake();

        $draft = $this->draftInApprovalMode();
        $this->assertSame(1, (int) $draft->is_locked);

        Notification::fake();
        $approved = new ApproveAgentMessageAction($draft)->execute();

        $this->assertSame(0, (int) $approved->is_locked, 'Approved message must be unlocked');
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification, array $channels, object $notifiable): bool =>
                $notifiable->routes['mail'] === 'prospect@test.example',
        );
    }

    public function testApproveWithEditUpdatesContentBeforeSend(): void
    {
        Notification::fake();

        $draft = $this->draftInApprovalMode();

        $approved = new ApproveAgentMessageAction($draft, 'Edited human reply')->execute();

        $this->assertStringContainsString('Edited human reply', (string) ($approved->message['content'] ?? ''));
    }

    public function testApproveOnNonLockedMessageThrows(): void
    {
        Notification::fake();

        $outbound = $this->draftWithApprovalOff();
        $this->assertSame(0, (int) $outbound->is_locked);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Message is not pending approval');

        new ApproveAgentMessageAction($outbound)->execute();
    }

    public function testRejectSoftDeletesDraftWithoutSending(): void
    {
        Notification::fake();

        $draft = $this->draftInApprovalMode();

        $this->assertTrue(new RejectAgentMessageAction($draft)->execute());
        $this->assertTrue((bool) $draft->fresh()->is_deleted);
        Notification::assertNothingSent();
    }

    public function testApproveUnsupportedVerbThrows(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $smsType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );

        $locked = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($smsType)
            ->create([
                'message' => ['content' => 'hi', 'chat_jid' => '+15550000000', 'from_ia' => true],
            ]);

        new RequestMessageApprovalAction(
            message: $locked,
            kind: MessageApproval::KIND_EMAIL_DRAFT,
            private: false,
        )->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Approval send is not supported');

        new ApproveAgentMessageAction($locked)->execute();
    }

    public function testApproveGeneratesSubjectWhenNoAnchorOrStoredSubject(): void
    {
        Notification::fake();
        AnonymousAgent::fake(['Quick sync this week']);

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $mailgunType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'mailgun-email'],
            ['name' => 'Mailgun Email']
        );

        // Outbound draft with NO subject and NO thread anchor on the lead.
        $draft = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($mailgunType)
            ->create([
                'message' => [
                    'content' => "Hola Claudio,\n\nBorrador de prueba para aprobación. ¿15 min esta semana?\n\n— Sally",
                    'chat_jid' => 'claudio@megsoft.io',
                    'from_me' => true,
                    'from_ia' => true,
                ],
            ]);

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $draft->getId(),
            'message_types_id' => $mailgunType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $draft = $draft->fresh();

        new RequestMessageApprovalAction(
            message: $draft,
            kind: MessageApproval::KIND_EMAIL_DRAFT,
            private: false,
        )->execute();

        new ApproveAgentMessageAction($draft)->execute();

        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification): bool {
                $subject = new \ReflectionProperty($notification, 'subject')->getValue($notification);

                // Generated subject, no "Re:" prefix (brand-new thread).
                return $subject === 'Quick sync this week';
            },
        );

        // Anchored on the lead so later follow-ups thread under it.
        $this->assertSame('Quick sync this week', (string) Lead::getById($lead->getId(), $app)->get('title_email_follow_up'));
    }

    /**
     * Run the responder with the company in APPROVAL mode and hand back the draft it held. The
     * seed/mode/run/fetch sequence is the arrange for most of this file; spelled out per test it drifts.
     */
    private function draftInApprovalMode(): Message
    {
        return $this->seedAndRespond(IntelligenceModeEnum::APPROVAL);
    }

    private function draftWithApprovalOff(): Message
    {
        return $this->seedAndRespond(null);
    }

    private function seedAndRespond(?IntelligenceModeEnum $mode): Message
    {
        ['app' => $app, 'company' => $company, 'channel' => $channel, 'inbound' => $inbound, 'agent' => $agent, 'session' => $session] =
            $this->seedInboundEmailScenario();

        if ($mode !== null) {
            $company->set(ConfigurationEnum::AGENT_AI_MODE->value, $mode->value);
        }

        $this->runResponder($channel, $inbound, $agent, $session);

        $outbound = $this->latestOutbound($app, $company, $channel);
        $this->assertNotNull($outbound, 'the responder persisted no outbound message');

        return $outbound;
    }

    private function runResponder(Channel $channel, Message $inbound, Agent $agent, $session): void
    {
        try {
            new AgentChannelResponderAction($channel, $inbound, $agent, $session)->execute([]);
        } catch (Throwable) {
            // Outbound delivery may throw in test env — persistence/lock ran first.
        }
    }

    private function latestOutbound(Apps $app, $company, Channel $channel): ?Message
    {
        return Message::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereJsonContains('message->from_ia', true)
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $channel->getId()))
            ->latest('id')
            ->first();
    }

    /**
     * @return array{app: Apps, company: \Kanvas\Companies\Models\Companies, channel: Channel, inbound: Message, agent: Agent, session: \Kanvas\Intelligence\Sessions\Models\Session, lead: Lead}
     */
    private function seedInboundEmailScenario(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(ConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());
        // Company settings cache survives DatabaseTransactions rollback, so reset the approval
        // mode to a known non-approval default; approval tests override it after seeding.
        $company->set(ConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::FULL_ON->value);

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'mailgun-email'],
            ['name' => 'Mailgun Email']
        );

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => SessionChannelService::createChannelSlug('email', 'prospect@test.example'),
            ],
            ['name' => 'Email Test', 'description' => 'Test email channel', 'users_id' => $user->getId()]
        );

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => [
                    'content' => 'Hello, I want to know more about your services',
                    'from_email' => 'prospect@test.example',
                    'subject' => 'Inquiry',
                    'from_me' => false,
                ],
                'is_locked' => 0,
                'is_un_response' => 0,
            ]);

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $inbound->getId(),
            'message_types_id' => $messageType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inbound = $inbound->fresh();
        $channel->addMessage($inbound);

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sales',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test',
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);

        $session = new CreateSessionAction(
            SessionDto::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => SessionChannelService::createCanalId('email', 'prospect@test.example'),
                'user' => [
                    'name' => $lead->people?->getName() ?: 'Lead',
                    'id' => $lead->people?->getId() ?? 0,
                    'email' => 'prospect@test.example',
                ],
                'agent' => $agent,
            ])
        )->execute();

        return [
            'app' => $app,
            'company' => $company,
            'channel' => $channel,
            'inbound' => $inbound,
            'agent' => $agent,
            'session' => $session,
            'lead' => $lead,
        ];
    }
}
