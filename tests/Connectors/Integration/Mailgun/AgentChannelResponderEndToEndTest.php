<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mailgun\Actions\AgentChannelResponderAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Throwable;

class AgentChannelResponderEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    public function testInboundEmailTriggersAgentReplyPersistedOnChannel(): void
    {
        Notification::fake();

        ['app' => $app, 'company' => $company, 'channel' => $channel, 'inbound' => $inbound, 'agent' => $agent, 'session' => $session] =
            $this->seedInboundEmailScenario();

        $action = new AgentChannelResponderAction($channel, $inbound, $agent, $session);

        try {
            $action->execute([]);
        } catch (Throwable) {
            // Outbound email delivery may throw in test env — persistence ran first.
        }

        $outbound = Message::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereJsonContains('message->from_ia', true)
            ->whereHas(
                'channels',
                fn ($q) => $q->where('channels.id', $channel->getId()),
            )
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound, 'Agent reply must be persisted on the channel');
        $this->assertStringContainsString('Hola Mundo', (string) ($outbound->message['content'] ?? ''));
        $this->assertSame((string) $session->uuid, (string) ($outbound->message['session_id'] ?? ''));
    }

    // Regression: when title_email_follow_up is set, the responder used it as the
    // subject VERBATIM (no "Re:"), which made Gmail treat the agent reply as a new
    // thread instead of a reply. The subject must always carry a "Re:" prefix.
    public function testAgentReplySubjectIsAlwaysRePrefixedSoItThreads(): void
    {
        Notification::fake();

        ['channel' => $channel, 'inbound' => $inbound, 'agent' => $agent, 'session' => $session, 'lead' => $lead] =
            $this->seedInboundEmailScenario();

        $lead->set('title_email_follow_up', 'Connecting dealer CRM & CMS for AI execution');

        $action = new AgentChannelResponderAction($channel, $inbound, $agent, $session);

        try {
            $action->execute([]);
        } catch (Throwable) {
        }

        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification): bool {
                $subject = new ReflectionProperty($notification, 'subject')->getValue($notification);

                return $subject === 'Re: Connecting dealer CRM & CMS for AI execution';
            },
        );
    }

    // A title that ALREADY starts with "Re:" must not be double-prefixed.
    public function testAgentReplySubjectDoesNotDoublePrefixRe(): void
    {
        Notification::fake();

        ['channel' => $channel, 'inbound' => $inbound, 'agent' => $agent, 'session' => $session, 'lead' => $lead] =
            $this->seedInboundEmailScenario();

        $lead->set('title_email_follow_up', 'Re: Connecting dealer CRM & CMS for AI execution');

        $action = new AgentChannelResponderAction($channel, $inbound, $agent, $session);

        try {
            $action->execute([]);
        } catch (Throwable) {
        }

        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification): bool {
                $subject = new ReflectionProperty($notification, 'subject')->getValue($notification);

                return $subject === 'Re: Connecting dealer CRM & CMS for AI execution';
            },
        );
    }

    // Cold inbound (no prior agent outreach) must persist the incoming subject as the thread
    // anchor so later follow-ups thread under it instead of starting a fresh thread.
    public function testColdInboundPersistsThreadAnchorWhenMissing(): void
    {
        Notification::fake();

        ['app' => $app, 'channel' => $channel, 'inbound' => $inbound, 'agent' => $agent, 'session' => $session, 'lead' => $lead] =
            $this->seedInboundEmailScenario();

        $this->assertEmpty($lead->get('title_email_follow_up'));

        try {
            new AgentChannelResponderAction($channel, $inbound, $agent, $session)->execute([]);
        } catch (Throwable) {
        }

        $this->assertSame('Inquiry', (string) Lead::getById($lead->getId(), $app)->get('title_email_follow_up'));
    }

    // First touch wins: an existing anchor (from outreach) must not be clobbered by inbound.
    public function testInboundDoesNotClobberExistingThreadAnchor(): void
    {
        Notification::fake();

        ['app' => $app, 'channel' => $channel, 'inbound' => $inbound, 'agent' => $agent, 'session' => $session, 'lead' => $lead] =
            $this->seedInboundEmailScenario();

        $lead->set('title_email_follow_up', 'Original Outreach Subject');

        try {
            new AgentChannelResponderAction($channel, $inbound, $agent, $session)->execute([]);
        } catch (Throwable) {
        }

        $this->assertSame(
            'Original Outreach Subject',
            (string) Lead::getById($lead->getId(), $app)->get('title_email_follow_up'),
        );
    }

    // Regression (Sentry KANVAS-ECOSYSTEM-5W0): a rule fanning every inbound message at the
    // Mailgun activity delivered Twilio SMS payloads here — no from_email, so the responder
    // crashed on the undefined key. It must skip, and persist nothing.
    public function testInboundWithoutFromEmailIsSkippedInsteadOfCrashing(): void
    {
        Notification::fake();

        ['app' => $app, 'company' => $company, 'channel' => $channel, 'inbound' => $inbound, 'agent' => $agent, 'session' => $session] =
            $this->seedInboundEmailScenario();

        $inbound->message = [
            'content' => '11am',
            'from_me' => false,
            'from_ia' => false,
            'chat_jid' => '+16503859777',
        ];
        $inbound->saveOrFail();

        $lastMessageId = (int) $inbound->getId();
        $skipped = null;

        try {
            new AgentChannelResponderAction($channel, $inbound->fresh(), $agent, $session)->execute([]);
        } catch (AgentReplySkippedException $e) {
            $skipped = $e;
        }

        $this->assertNotNull($skipped, 'A non-email inbound must be skipped, not crash on from_email');

        $outbound = Message::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('id', '>', $lastMessageId)
            ->whereJsonContains('message->from_ia', true)
            ->whereHas(
                'channels',
                fn ($q) => $q->where('channels.id', $channel->getId()),
            )
            ->exists();

        $this->assertFalse($outbound, 'No agent reply should be persisted for a non-email inbound');
    }

    // Regression: without this marker the agent had no way to know a new attachment existed and reused an older one's summary from chat history instead.
    public function testCurrentAttachmentIsSurfacedAsAnExplicitMarker(): void
    {
        ['channel' => $channel, 'inbound' => $inbound, 'agent' => $agent, 'session' => $session] =
            $this->seedInboundEmailScenario();

        $filesystem = $this->makeFilesystemRow('02_VATIT_INV2607GB30K00006851.pdf');
        $inbound->addFile($filesystem, 'attachment-1');
        $inbound = $inbound->fresh();

        $action = new AgentChannelResponderAction($channel, $inbound, $agent, $session);
        $markers = new ReflectionMethod($action, 'currentAttachmentMarkers')->invoke($action);

        $this->assertStringContainsString('filesystem_id: ' . $filesystem->getId(), $markers);
        $this->assertStringContainsString('"02_VATIT_INV2607GB30K00006851.pdf"', $markers);
    }

    public function testNoAttachmentMarkerWhenNothingIsAttached(): void
    {
        ['channel' => $channel, 'inbound' => $inbound, 'agent' => $agent, 'session' => $session] =
            $this->seedInboundEmailScenario();

        $action = new AgentChannelResponderAction($channel, $inbound, $agent, $session);
        $markers = new ReflectionMethod($action, 'currentAttachmentMarkers')->invoke($action);

        $this->assertSame('', $markers);
    }

    private function makeFilesystemRow(string $name): Filesystem
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $row = new Filesystem();
        $row->apps_id = $app->getId();
        $row->companies_id = $company->getId();
        $row->users_id = $user->getId();
        $row->name = $name;
        $row->path = 'test/' . $name;
        $row->url = 'https://example.test/' . $name;
        $row->size = '12345';
        $row->file_type = 'pdf';
        $row->save();

        return $row;
    }

    /**
     * @return array{app: Apps, company: \Kanvas\Companies\Models\Companies, channel: Channel, inbound: Message, agent: Agent, session: \Kanvas\Intelligence\Sessions\Models\Session, lead: Lead}
     */
    private function seedInboundEmailScenario(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());
        // Company settings survive DatabaseTransactions rollback; reset approval mode so a leaked
        // APPROVAL from the approval test suite doesn't suppress auto-send here.
        $company->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::FULL_ON->value);

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
