<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\ConnectWhatsAppSessionAction;
use Kanvas\Connectors\WaSender\Actions\DisconnectWhatsAppSessionAction;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Connectors\WaSender\Workflows\AgentChannelResponderActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Mockery;
use Tests\TestCase;

class ConnectWhatsAppSessionActionTest extends TestCase
{
    private function ensureActionRow(string $modelName): void
    {
        // Both Workflow\Models\WorkflowAction and Rules\Models\Action map to the workflow `actions`
        // table (minimal: name, model_name, is_deleted). Normally seeded by kanvas:workflow-sync-actions.
        $table = DB::connection('workflow')->table('actions');
        if (! $table->where('model_name', $modelName)->exists()) {
            $table->insert([
                'name' => class_basename($modelName),
                'model_name' => $modelName,
                'is_deleted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function makeReceptionistAgent(Apps $app, int $companyId): Agent
    {
        $agentType = AgentType::factory()->withAppId($app->getId())->create([
            'name' => 'Receptionist (connect test)',
            'provider' => 'neuron',
        ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($companyId)
            ->create(['agent_type_id' => $agentType->getId()]);
    }

    public function testConnectCreatesReceiverBindsAgentStoresSecretAndReturnsQr(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->ensureActionRow(ProcessWaSenderWebhookJob::class);
        $this->ensureActionRow(AgentChannelResponderActivity::class);
        RuleType::firstOrCreate(['name' => WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value]);
        SystemModules::firstOrCreate(
            ['model_name' => Channel::class],
            ['name' => 'Channels', 'slug' => 'channels', 'description' => 'Channels system module']
        );

        $agent = $this->makeReceptionistAgent($app, $company->getId());

        $captured = [];
        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldReceive('createAndConnectSession')
            ->once()
            ->andReturnUsing(function (...$args) use (&$captured): array {
                $captured = $args;

                return [
                    'session' => ['id' => 909, 'status' => 'need_scan', 'webhook_secret' => 'fb61be92ddb7935e0cedcec58e470f6c', 'api_key' => 'sess_909_send_key'],
                    'connection' => ['qr_code' => 'base64QRDATA', 'status' => 'need_scan'],
                ];
            });

        $result = new ConnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            user: $user,
            agent: $agent,
            phoneNumber: '18095551234',
            sessionService: $sessionService,
        )->execute();

        // Result shape
        $this->assertSame(909, $result['session_id']);
        $this->assertSame('need_scan', $result['status']);
        $this->assertSame('base64QRDATA', $result['qr_code']);

        // Session name defaulted to phone; every event we act on is subscribed.
        $this->assertSame('18095551234', $captured[0]);
        $events = null;
        foreach ($captured as $arg) {
            if (is_array($arg) && in_array('messages.upsert', $arg, true)) {
                $events = $arg;

                break;
            }
        }
        $this->assertNotNull($events);
        $this->assertSame(WebhookEventEnum::subscribable(), $events);

        // The inbound mirrors of messages.upsert are recognised but never subscribed to — one
        // group message would otherwise arrive four times.
        foreach (WebhookEventEnum::duplicateMessageEvents() as $duplicate) {
            $this->assertNotContains($duplicate->value, $events);
        }

        // Receiver created + webhook_secret stored (so signature verification passes)
        $receiver = ReceiverWebhook::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($receiver);
        $this->assertSame('fb61be92ddb7935e0cedcec58e470f6c', $receiver->configuration['webhook_secret'] ?? null);
        $this->assertSame(909, $receiver->configuration['session_id'] ?? null);

        // The agent owns the connection — session id / phone / receiver id stored on it.
        $fresh = Agent::getById($agent->getId(), $app);
        $this->assertSame('909', (string) $fresh->get('whatsapp_session_id'));
        $this->assertSame('18095551234', (string) $fresh->get('whatsapp_phone_number'));
        $this->assertSame((string) $receiver->getId(), (string) $fresh->get('whatsapp_receiver_id'));

        // The session's own api_key becomes the company wasender_api_key, so the unchanged send path
        // (MessageService reads company API_KEY first) authenticates /api/send-message with it.
        $this->assertSame('sess_909_send_key', (string) $company->get('wasender_api_key'));

        // Agent bound on the AFTER_ADDING_MESSAGE_TO_CHANNEL rule
        $rule = Rule::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('name', 'WhatsApp Receptionist responder')
            ->first();
        $this->assertNotNull($rule);
        $this->assertSame($agent->getId(), (int) ($rule->params['agent_id'] ?? 0));

        // Rule is scoped to WhatsApp channels only (mirrors prod: slug matches /wa/), so it doesn't
        // fire the WhatsApp responder on email/SMS/other channels.
        $this->assertTrue(
            $rule->getRulesConditions()
                ->where('attribute_name', 'slug')
                ->where('operator', 'matches')
                ->where('value', '/wa/')
                ->exists(),
            'Rule must carry the slug-matches-/wa/ condition scoping it to WhatsApp channels'
        );
    }

    public function testDisconnectPauseUnlinksButKeepsSession(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeReceptionistAgent($app, $company->getId());
        $agent->set('whatsapp_session_id', 555);

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldReceive('disconnectSession')->once()->with(555)->andReturn(['status' => 'disconnected']);
        $sessionService->shouldNotReceive('deleteSession');

        $result = new DisconnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            agent: $agent,
            remove: false,
            sessionService: $sessionService,
        )->execute();

        $this->assertTrue($result);
        // Session id kept so it can reconnect.
        $this->assertSame('555', (string) Agent::getById($agent->getId(), $app)->get('whatsapp_session_id'));
    }

    public function testDisconnectRemoveDeletesSessionAndClearsAgent(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeReceptionistAgent($app, $company->getId());
        $agent->set('whatsapp_session_id', 777);
        $agent->set('whatsapp_phone_number', '18095551234');

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldReceive('deleteSession')->once()->with(777)->andReturn(['status' => 'deleted']);
        $sessionService->shouldNotReceive('disconnectSession');

        $result = new DisconnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            agent: $agent,
            remove: true,
            sessionService: $sessionService,
        )->execute();

        $this->assertTrue($result);
        $fresh = Agent::getById($agent->getId(), $app);
        $this->assertSame('', (string) $fresh->get('whatsapp_session_id'));
        $this->assertSame('', (string) $fresh->get('whatsapp_phone_number'));
    }

    public function testDisconnectReturnsFalseWhenAgentHasNoConnection(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeReceptionistAgent($app, $company->getId());

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldNotReceive('disconnectSession');
        $sessionService->shouldNotReceive('deleteSession');

        $result = new DisconnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            agent: $agent,
            sessionService: $sessionService,
        )->execute();

        $this->assertFalse($result);
    }
}
