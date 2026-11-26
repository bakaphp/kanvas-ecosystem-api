<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Connectors\WaSender\Actions\ConnectWhatsAppSessionAction;
use Kanvas\Connectors\WaSender\Actions\DisconnectWhatsAppSessionAction;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Workflow\Rules\Models\Rule;
use Mockery;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

class ConnectWhatsAppSessionActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    // Linking a number now gives the company a receiver, an integration row and a connection.
    protected $connectionsToTransact = [null, 'workflow', 'intelligence'];

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

    public function testConnectPointsTheSessionAtTheCommunicationReceiverStoresSecretAndReturnsQr(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

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

        // The session posts to the company's communication receiver, which keeps its secret.
        $receiver = new CommunicationReceivers()->find($app, $company, 'wasender');
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

        // The agent answers because it owns the number; no rule is made for it.
        $this->assertFalse(
            Rule::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('name', 'WhatsApp Receptionist responder')
                ->where('created_at', '>=', now()->subMinute())
                ->exists()
        );
        $this->assertContains($receiver->getUrl(), $captured);
    }

    public function testConnectingTheNumberAnAgentAlreadyLinkedReusesItsSession(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeReceptionistAgent($app, $company->getId());
        $agent->set('whatsapp_session_id', 777);

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldReceive('getSession')->with(777)->andReturn(['id' => 777, 'phone_number' => '+1 809 555 1234']);
        $sessionService->shouldReceive('connectSession')->once()->with(777, true)->andReturn(['status' => 'need_scan', 'qr_code' => 'QR-AGAIN']);
        $sessionService->shouldNotReceive('createAndConnectSession');
        $sessionService->shouldNotReceive('deleteSession');

        $result = new ConnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            user: auth()->user(),
            agent: $agent,
            phoneNumber: '+18095551234',
            sessionService: $sessionService,
        )->execute();

        $this->assertSame(777, $result['session_id']);
        $this->assertSame('QR-AGAIN', $result['qr_code']);
    }

    public function testConnectingAnotherNumberReplacesTheSessionTheAgentHad(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeReceptionistAgent($app, $company->getId());
        $agent->set('whatsapp_session_id', 777);

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldReceive('getSession')->with(777)->andReturn(['id' => 777, 'phone_number' => '+18095550000']);
        $sessionService->shouldReceive('deleteSession')->once()->with(777)->andReturn(['status' => 'deleted']);
        $sessionService->shouldReceive('createAndConnectSession')->once()->andReturn([
            'session' => ['id' => 888, 'webhook_secret' => 'new-secret', 'api_key' => 'new-key'],
            'connection' => ['status' => 'need_scan', 'qr_code' => 'QR-NEW'],
        ]);

        $result = new ConnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            user: auth()->user(),
            agent: $agent,
            phoneNumber: '+18095551234',
            sessionService: $sessionService,
        )->execute();

        $this->assertSame(888, $result['session_id']);
        $this->assertSame('888', (string) Agent::getById($agent->getId(), $app)->get('whatsapp_session_id'));
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

    public function testRemovingOneAgentsSessionLeavesAnotherAgentsLiveNumberAlone(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $stale = $this->makeReceptionistAgent($app, $company->getId());
        $stale->set('whatsapp_session_id', 777);
        $live = $this->connection('wasender', '+18095550144', ['config' => ['session_id' => 999]]);

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldReceive('deleteSession')->once()->with(777)->andReturn(['status' => 'deleted']);

        new DisconnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            agent: $stale,
            remove: true,
            sessionService: $sessionService,
        )->execute();

        $this->assertTrue($live->fresh()->isLive());
    }

    public function testRemovingTheSessionRetiresItsNumberWithoutBlockingItFromComingBack(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeReceptionistAgent($app, $company->getId());
        $agent->set('whatsapp_session_id', 777);
        $agent->set('whatsapp_phone_number', '18095550145');
        $connection = $this->connection('wasender', '+18095550145', ['config' => ['session_id' => 777]]);

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldReceive('deleteSession')->once()->with(777)->andReturn(['status' => 'deleted']);

        new DisconnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            agent: $agent,
            remove: true,
            sessionService: $sessionService,
        )->execute();

        $this->assertFalse($connection->fresh()->isLive());
        $this->assertArrayNotHasKey('retired_accounts', $connection->integrationCompany->fresh()->config ?? []);
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
