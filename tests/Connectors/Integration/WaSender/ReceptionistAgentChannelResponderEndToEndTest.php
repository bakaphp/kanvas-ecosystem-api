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
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\Stubs\Intelligence\ReceptionistNeuronAgentStub;
use Tests\TestCase;
use Throwable;

/**
 * End-to-end simulation: inbound WhatsApp message → kernel dispatch → the REAL ReceptionistAgent
 * (with its full tool set, LLM faked) → auto-reply persisted on the channel. Outbound HTTP is
 * faked so the test never touches the real WaSender API, and no phone/QR is needed. The lead is
 * put in FULL_ON mode to reflect the receptionist config path.
 */
class ReceptionistAgentChannelResponderEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    public function testInboundWhatsAppGetsReceptionistAutoReply(): void
    {
        Http::fake(); // any outbound HTTP (WaSender send) becomes a no-op

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Connector outbound persists as the company AI-agent user.
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $people = $lead->people;

        // Receptionist config: this lead auto-replies (FULL_ON).
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

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
                'slug' => SessionChannelService::createChannelSlug('whatsapp', '18095550000'),
            ],
            ['name' => 'WA Reception Test', 'description' => 'Test WA channel', 'users_id' => $user->getId()]
        );

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => [
                    'content' => 'Hi, what are your hours and can I book an appointment?',
                    'from_me' => false,
                    'chat_jid' => '18095550000@s.whatsapp.net',
                    'raw_data' => [
                        'message' => ['conversation' => 'Hi, what are your hours and can I book an appointment?'],
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

        // Receptionist agent — real handler, LLM faked to a fixed Spanish greeting.
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Receptionist (Neuron Test)',
                'provider' => 'neuron',
                'handler' => ReceptionistNeuronAgentStub::class,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Receptionist',
                'agent_type_id' => $agentType->getId(),
                'role' => [],
            ]);

        $session = new CreateSessionAction(
            SessionDto::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => SessionChannelService::createCanalId('whatsapp', '18095550000'),
                'user' => [
                    'name' => $people->getName() ?: 'Lead',
                    'id' => $people->getId(),
                    'email' => $people->getEmails()->first()?->value,
                ],
                'agent' => $agent,
            ])
        )->execute();

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
            // The agent dispatch + reply persistence ran before that point.
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

        $this->assertNotNull($outbound, 'Receptionist reply must be persisted on the channel');
        $this->assertStringContainsString('gracias por escribir', mb_strtolower((string) ($outbound->message['content'] ?? '')));
        $this->assertSame((string) $session->uuid, (string) ($outbound->message['session_id'] ?? ''));
    }
}
