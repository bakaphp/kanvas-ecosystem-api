<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ReadAi\Handlers\ReadAiHandler;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\SetMeetingRoutingAction;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceSettingEnum;
use Kanvas\NervousSystem\Orchestrator\Webhooks\ProcessOrchestratorSignalJob;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Intelligence\NervousSystem\Orchestrator\Concerns\MakesMeetingRoutingFixtures;
use Tests\TestCase;

class MeetingRoutingIntegrationTest extends TestCase
{
    use DatabaseTransactions;
    use MakesMeetingRoutingFixtures;

    protected array $connectionsToTransact = ['mysql', 'workflow', 'intelligence'];

    private const array ALL = ['lead', 'organization', 'project'];

    /**
     * @param array<string, mixed> $configuration
     */
    private function receiver(Companies $company, array $configuration = ['signal_source' => 'read_ai']): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate(['model_name' => ProcessOrchestratorSignalJob::class], ['name' => 'Orchestrator Signal Ingest']);

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: app(Apps::class),
                company: $company,
                user: $this->testUser(),
                action: $action,
                name: 'Orchestrator ingest',
                description: 'Meeting routing switch test',
                configuration: $configuration,
                is_active: true,
                run_async: true,
            ),
        )->execute();
    }

    /**
     * @return list<string>|null
     */
    private function destinations(ReceiverWebhook $receiver): ?array
    {
        return $receiver->refresh()->configuration[ProcessOrchestratorSignalJob::DESTINATIONS] ?? null;
    }

    private function handler(Companies $company): ReadAiHandler
    {
        $row = Integrations::query()->where('name', 'read_ai')->where('apps_id', 0)->firstOrFail();

        return new ReadAiHandler(
            app: app(Apps::class),
            company: $company,
            region: Regions::query()->where('apps_id', app(Apps::class)->getId())->first() ?? Regions::query()->firstOrFail(),
            data: ['signing_key' => base64_encode('read-ai-test-key')],
            integration: $row,
        );
    }

    public function testEnablingSetsAllLevelsAndDisablingRestoresProjectsOnly(): void
    {
        $company = $this->freshCompany();
        $receiver = $this->receiver($company);

        new SetMeetingRoutingAction(app(Apps::class), $company, true)->execute();
        $this->assertSame(self::ALL, $this->destinations($receiver));

        new SetMeetingRoutingAction(app(Apps::class), $company, false)->execute();
        $this->assertNull($this->destinations($receiver));
        $this->assertSame('read_ai', $receiver->configuration['signal_source']);
    }

    public function testAListSetByHandIsNeverTouched(): void
    {
        $company = $this->freshCompany();
        $receiver = $this->receiver($company, ['signal_source' => 'read_ai', 'destinations' => ['lead']]);

        $this->assertSame(0, new SetMeetingRoutingAction(app(Apps::class), $company, true)->execute());
        $this->assertSame(0, new SetMeetingRoutingAction(app(Apps::class), $company, false)->execute());
        $this->assertSame(['lead'], $this->destinations($receiver));
    }

    public function testConnectingReadAiTurnsRoutingOnAndDisconnectingTurnsItOff(): void
    {
        $company = $this->freshCompany();
        $receiver = $this->receiver($company);
        $handler = $this->handler($company);

        $handler->setup();
        $handler->saved(new IntegrationsCompany(['is_active' => true]));
        $this->assertSame(self::ALL, $this->destinations($receiver));
        $this->assertNotNull($company->get(SignalSourceSettingEnum::READ_AI_SIGNING_KEY->value));

        $handler->activeChanged(new IntegrationsCompany(['is_active' => false]), false);
        $this->assertNull($this->destinations($receiver));

        $handler->activeChanged(new IntegrationsCompany(['is_active' => true]), true);
        $this->assertSame(self::ALL, $this->destinations($receiver));

        $handler->teardown();
        $this->assertNull($this->destinations($receiver));
        $this->assertNull($company->get(SignalSourceSettingEnum::READ_AI_SIGNING_KEY->value));
    }

    public function testSavingAnInactiveConnectionLeavesRoutingOff(): void
    {
        $company = $this->freshCompany();
        $receiver = $this->receiver($company);

        $this->handler($company)->saved(new IntegrationsCompany(['is_active' => false]));

        $this->assertNull($this->destinations($receiver));
    }
}
