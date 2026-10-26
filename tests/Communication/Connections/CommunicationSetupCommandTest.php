<?php

declare(strict_types=1);

namespace Tests\Communication\Connections;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Connections\Actions\ProbeConnectionsAction;
use Kanvas\Communication\Connections\Actions\ReleaseUnusedRegistrationsAction;
use Kanvas\Communication\Connections\Actions\RunConnectionCleanupAction;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeProvisioner;
use Tests\TestCase;

final class CommunicationSetupCommandTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private FakeProvisioner $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->useFakeProvisioner();
    }

    /**
     * The worker that should have provisioned it never ran; the sweep finds the pending row.
     */
    public function testAnIntegrationNobodySetUpBecomesReady(): void
    {
        $integration = $this->integrateMessaging(['+18095550300']);

        $this->sweep();

        $this->assertSame(SetupStatusEnum::READY, $this->connectionOf($integration)->setup_status);
    }

    public function testOneFailureDoesNotStopOthers(): void
    {
        $broken = $this->integrateMessaging(['+18095550301']);
        $healthy = $this->integrateMessaging(['+18095550302']);
        $this->vendor->failRegistrations = 1;

        $this->sweep();

        $statuses = [$this->connectionOf($broken)->setup_status, $this->connectionOf($healthy)->setup_status];
        sort($statuses);

        $this->assertEqualsCanonicalizing([SetupStatusEnum::FAILED, SetupStatusEnum::READY], $statuses);
    }

    public function testAConnectionOfARetiredProviderDoesNotStopTheProbe(): void
    {
        $this->connection('retired-vendor', '+18095550303');
        $live = $this->connection('fake', '+18095550304');

        new ProbeConnectionsAction($this->currentApp())->execute();

        $this->assertNotNull($live->fresh()->last_probed_at);
    }

    public function testALegacyRowWithoutEvidenceIsReportedNotGuessed(): void
    {
        $integration = $this->integrateMessaging([]);
        IntegrationsCompany::query()->whereKey($integration->getKey())->update(['apps_id' => null]);

        $this->artisan('communication:setup', ['--app' => $this->currentApp()->getId()])
            ->expectsOutputToContain("legacy integration={$integration->getId()} company={$integration->companies_id} unresolved")
            ->assertSuccessful();

        $this->assertNull($integration->fresh()->apps_id);
    }

    public function testAnOperatorAssignsALegacyRow(): void
    {
        $integration = $this->integrateMessaging(['+18095550303']);
        IntegrationsCompany::query()->whereKey($integration->getKey())->update(['apps_id' => null]);

        $this->artisan('communication:setup', [
            '--app' => $this->currentApp()->getId(),
            '--assign-integration' => $integration->getId(),
        ])->assertSuccessful();

        $this->assertSame($this->currentApp()->getId(), (int) $integration->fresh()->apps_id);
    }

    /**
     * After the last number is gone the receiver stays on for its outstanding deliveries' late
     * statuses, and goes off only once the provider can no longer report on any of them.
     */
    public function testTheReceiverOutlivesTheLastConnectionUntilItsDeliveriesClose(): void
    {
        $integration = $this->integrateMessaging(['+18095550304']);
        $this->runSetup($integration);
        $connection = $this->connectionOf($integration);
        $delivery = $this->sentDeliveryOn($connection);

        $this->resaveIntegration($integration, []);
        $this->runSetup($integration);
        new RunConnectionCleanupAction($connection->fresh())->execute();

        new ReleaseUnusedRegistrationsAction($this->currentApp())->execute();
        $this->assertTrue($this->inboundReceiver()->is_active);

        $delivery->forceFill(['events_closed_at' => now()])->saveOrFail();
        new ReleaseUnusedRegistrationsAction($this->currentApp())->execute();

        $this->assertFalse($this->inboundReceiver()->is_active);
    }

    private function sweep(): void
    {
        $this->artisan('communication:setup', ['--app' => $this->currentApp()->getId()])->assertSuccessful();
    }

    private function connectionOf(IntegrationsCompany $integration): Connection
    {
        return Connection::query()->where('integrations_company_id', $integration->getId())->latest('id')->firstOrFail();
    }

    private function sentDeliveryOn(Connection $connection): Delivery
    {
        ChannelDefault::query()->updateOrCreate(
            ['apps_id' => $connection->apps_id, 'companies_id' => $connection->companies_id, 'channel' => 'sms'],
            ['connection_id' => $connection->getId()]
        );

        return $this->communication()
            ->send(
                $this->personWithCellphone(),
                'Hello',
                channels: ['sms'],
                sync: true
            )
            ->delivery(ChannelTypeEnum::SMS);
    }

    private function inboundReceiver(): ReceiverWebhook
    {
        return new CommunicationReceivers()->find($this->currentApp(), $this->currentCompany(), 'fake')
            ?? $this->fail('Setup made no receiver.');
    }
}
