<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Tests\Traits\AttachesMediaToMessage;
use Throwable;

class AgentChannelResponderEndToEndTest extends TestCase
{
    use AttachesMediaToMessage;
    use DatabaseTransactions;

    public static function senderProvider(): array
    {
        return [
            'company number from the rule' => [null, '+15555555555'],
            'agent number wins' => ['+15550002222', '+15550002222'],
        ];
    }

    #[DataProvider('senderProvider')]
    public function testInboundSmsTriggersAgentReplyPersistedOnChannel(?string $agentNumber, string $expectedFrom): void
    {
        [$channel, $inbound, $agent, $session, $app, $company] = $this->seedSmsScenario($agentNumber);

        $action = new AgentChannelResponderAction(
            $channel,
            $inbound,
            $agent,
            $session,
        );

        try {
            $action->execute(['from' => '+15555555555']);
        } catch (Throwable) {
            // Outbound Twilio API call may throw in test env — persistence ran first.
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

        // The channel already carried the customer's inbound message, so "first" has to be
        // counted over outbound history — otherwise a cold inbound text eats the clause.
        $this->assertStringContainsString(
            SmsOptOutNoticeService::NOTICE,
            (string) ($outbound->message['content'] ?? ''),
            'our first reply on a customer-opened SMS conversation must carry the opt-out notice'
        );
        $this->assertSame($expectedFrom, $outbound->get('from_number'));
    }

    public function testAnImageOnlyMmsReachesTheAgentAsAPicture(): void
    {
        [$channel, $inbound, $agent, $session] = $this->seedSmsScenario(null, content: '');
        $this->attachImage($inbound);
        $inbound = $inbound->fresh();

        try {
            new AgentChannelResponderAction(
                $channel,
                $inbound,
                $agent,
                $session,
            )->execute(['from' => '+15555555555']);
        } catch (Throwable) {
            // Outbound Twilio API call may throw in test env — the turn ran first.
        }

        $this->assertModelSawAnImageWithText('attachment');

        $outbound = Message::query()
            ->whereJsonContains('message->from_ia', true)
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $channel->getId()))
            ->latest('id')
            ->first();
        $this->assertNotNull($outbound, 'A picture without words still gets an answer');
        $this->assertStringContainsString('Hola Mundo', (string) ($outbound->message['content'] ?? ''));
    }

    /**
     * The sales agent in tests runs on a provider that cannot listen, like Claude or OpenAI chat in
     * production. The voice note reaches it as words from the audio provider, never as a block it
     * would drop.
     */
    public function testAVoiceNoteIsTranscribedForAnAgentWhoseModelCannotListen(): void
    {
        $this->app->instance(
            AttachmentDescriptionService::AUDIO_PROVIDER_BINDING,
            new FakeNeuronProvider('turn right at the gas station and I will be there'),
        );
        [$channel, $inbound, $agent, $session] = $this->seedSmsScenario(null, content: '');
        $this->attachVoiceNote($inbound);
        $inbound = $inbound->fresh();

        try {
            new AgentChannelResponderAction(
                $channel,
                $inbound,
                $agent,
                $session,
            )->execute(['from' => '+15555555555']);
        } catch (Throwable) {
            // Outbound Twilio API call may throw in test env — the turn ran first.
        }

        $this->assertModelSawATranscriptNotAudio('turn right at the gas station');

        $outbound = Message::query()
            ->whereJsonContains('message->from_ia', true)
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $channel->getId()))
            ->latest('id')
            ->first();
        $this->assertNotNull($outbound, 'A voice note still gets an answer');
    }

    /**
     * @return array{0: Channel, 1: Message, 2: Agent, 3: Session, 4: Apps, 5: Companies}
     */
    private function seedSmsScenario(?string $agentNumber, string $content = 'Hello via SMS'): array
    {
        Http::fake();

        // Each run needs its own SMS channel: the assertion below is on the channel's first outbound.
        $customerPhone = '1999' . fake()->unique()->numerify('#######');

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => SessionChannelService::createChannelSlug('sms', $customerPhone),
            ],
            ['name' => 'SMS Test', 'description' => 'Test SMS channel', 'users_id' => $user->getId()]
        );

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => [
                    'content' => $content,
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
                'sms_config' => $agentNumber !== null ? ['phone_number' => $agentNumber] : null,
            ]);

        $session = new CreateSessionAction(
            SessionDto::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => SessionChannelService::createCanalId('sms', $customerPhone),
                'user' => [
                    'name' => $lead->people?->getName() ?: 'Lead',
                    'id' => $lead->people?->getId() ?? 0,
                    'email' => null,
                ],
                'agent' => $agent,
            ])
        )->execute();

        return [$channel, $inbound, $agent, $session, $app, $company];
    }
}
