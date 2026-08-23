<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\Stubs\Intelligence\StructuredNeuronAgentStub;
use Tests\TestCase;
use Throwable;

/**
 * End-to-end: inbound WaSender Message → kernel dispatch → Neuron agent reply
 * persisted on channel. Outbound HTTP is faked so the test never touches the
 * real WaSender API. Proves the post-refactor flow works without DB+rule setup.
 */
class AgentChannelResponderEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    public function testInboundWhatsAppTriggersAgentReplyPersistedOnChannel(): void
    {
        [$channel, $inbound, $agent, $session] = $this->bootScenario(SalesNeuronAgentStub::class);

        $this->runResponder($channel, $inbound, $agent, $session);

        $outbound = $this->latestAgentReply($channel);

        $this->assertNotNull($outbound, 'Agent reply must be persisted on the channel');
        $this->assertStringContainsString('Hola Mundo', (string) ($outbound->message['content'] ?? ''));
        $this->assertSame((string) $session->uuid, (string) ($outbound->message['session_id'] ?? ''));
        $this->assertArrayNotHasKey('response_json', $outbound->message);
    }

    /**
     * An agent that answers with a whole record loses everything but the body once ChatHelper picks
     * the outbound text out of it — the decoded envelope rides along so a later activity (WordPress
     * publish, a CRM push) can still see the title, terms and status.
     */
    public function testStructuredAgentReplyKeepsItsEnvelopeOnTheMessage(): void
    {
        [$channel, $inbound, $agent, $session] = $this->bootScenario(StructuredNeuronAgentStub::class);

        $this->runResponder($channel, $inbound, $agent, $session);

        $outbound = $this->latestAgentReply($channel);

        $this->assertNotNull($outbound, 'Agent reply must be persisted on the channel');
        $this->assertSame('Hola Mundo', (string) ($outbound->message['content'] ?? ''));

        $envelope = $outbound->message['response_json'] ?? null;

        $this->assertIsArray($envelope, 'The decoded envelope must be stored alongside the reply text');
        $this->assertSame('Education accelerates classroom construction in El Seibo', $envelope['title']);
        $this->assertSame(['National', 'Education'], $envelope['categories']);
        $this->assertSame('draft', $envelope['status']);
    }

    /**
     * @return array{0: Channel, 1: Message, 2: Agent, 3: Session}
     */
    private function bootScenario(string $handler): array
    {
        Http::fake(); // any outbound HTTP (WaSender send) becomes a no-op

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Company needs an AI-agent user — the connector outbound persists as this user.
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        // Lead / People / Channel / inbound Message — Lead factory creates the People internally
        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $people = $lead->people;

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'whatsapp'],
            ['name' => 'WhatsApp']
        );

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => SessionChannelService::createChannelSlug('whatsapp', '19999999999'),
            ],
            ['name' => 'WA Test', 'description' => 'Test WA channel', 'users_id' => $user->getId()]
        );

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => [
                    'content' => 'Hello, I am interested in a car',
                    'from_me' => false,
                    'chat_jid' => '19999999999@s.whatsapp.net',
                    'raw_data' => [
                        'message' => ['conversation' => 'Hello, I am interested in a car'],
                    ],
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
                'handler' => $handler,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sales',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test sales agent',
                'instructions' => 'Always respond with: Hola Mundo',
                'output_format' => 'plain text',
            ]);

        // Session — same shape WaSender activity creates
        $session = new CreateSessionAction(
            SessionDto::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => SessionChannelService::createCanalId('whatsapp', '19999999999'),
                'user' => [
                    'name' => $people->getName() ?: 'Lead',
                    'id' => $people->getId(),
                    'email' => $people->getEmails()->first()?->value,
                ],
                'agent' => $agent,
            ])
        )->execute();

        return [$channel, $inbound, $agent, $session];
    }

    /**
     * Drives the action directly (skips the executeIntegration wrapper — same approach as
     * tests/Connectors/Integration/Twilio/AgentChannelResponderActionTest.php). The outbound
     * WaSender API call may throw without real credentials, but that happens AFTER persistence.
     */
    private function runResponder(
        Channel $channel,
        Message $inbound,
        Agent $agent,
        Session $session
    ): void {
        $action = new AgentChannelResponderAction(
            $channel,
            $inbound,
            $agent,
            $session,
        );

        try {
            $action->execute([]);
        } catch (Throwable) {
            // Expected: outbound WaSender API call fails without real credentials.
        }
    }

    private function latestAgentReply(Channel $channel): ?Message
    {
        return Message::query()
            ->where('apps_id', app(Apps::class)->getId())
            ->where('companies_id', auth()->user()->getCurrentCompany()->getId())
            ->whereJsonContains('message->from_ia', true)
            ->whereHas(
                'channels',
                fn ($q) => $q->where('channels.id', $channel->getId()),
            )
            ->latest('id')
            ->first();
    }
}
