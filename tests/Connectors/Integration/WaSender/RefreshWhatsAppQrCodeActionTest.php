<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\RefreshWhatsAppQrCodeAction;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Mockery;
use Tests\TestCase;

class RefreshWhatsAppQrCodeActionTest extends TestCase
{
    private function makeAgent(Apps $app, int $companyId): Agent
    {
        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($companyId)
            ->create(['agent_type_id' => $agentType->getId()]);
    }

    public function testReturnsFreshQrFromConnectResponse(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent($app, $company->getId());
        $agent->set('whatsapp_session_id', 777);

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldReceive('connectSession')
            ->once()
            ->with(777, true)
            ->andReturn(['status' => 'need_scan', 'qr_code' => 'FRESH_QR']);
        $sessionService->shouldNotReceive('getSessionQrCode');

        $result = new RefreshWhatsAppQrCodeAction(
            app: $app,
            company: $company,
            agent: $agent,
            sessionService: $sessionService,
        )->execute();

        $this->assertSame(777, $result['session_id']);
        $this->assertSame('need_scan', $result['status']);
        $this->assertSame('FRESH_QR', $result['qr_code']);
    }

    public function testFallsBackToQrEndpointWhenConnectHasNoQr(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent($app, $company->getId());
        $agent->set('whatsapp_session_id', 555);

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldReceive('connectSession')->once()->with(555, true)->andReturn(['status' => 'need_scan']);
        $sessionService->shouldReceive('getSessionQrCode')->once()->with(555)->andReturn(['qrCode' => 'QR_FROM_ENDPOINT']);

        $result = new RefreshWhatsAppQrCodeAction(
            app: $app,
            company: $company,
            agent: $agent,
            sessionService: $sessionService,
        )->execute();

        $this->assertSame('QR_FROM_ENDPOINT', $result['qr_code']);
    }

    public function testThrowsWhenAgentHasNoSession(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent($app, $company->getId());

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldNotReceive('connectSession');

        $this->expectException(ValidationException::class);

        new RefreshWhatsAppQrCodeAction(
            app: $app,
            company: $company,
            agent: $agent,
            sessionService: $sessionService,
        )->execute();
    }
}
