<?php

declare(strict_types=1);

namespace Tests\Communication\Connections;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Connections\Actions\ReleaseUnusedRegistrationsAction;
use Kanvas\Communication\Connections\Actions\RetireConnectionAction;
use Kanvas\Communication\Connections\Actions\RunConnectionCleanupAction;
use Kanvas\Communication\Connections\DataTransferObject\SetupReport;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\InboundClaim;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeProvisioner;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\TestCase;

/**
 * §10 on a provider-neutral fake: what completing an integration sets up, and how a change of
 * selection, a retry and a teardown leave it.
 */
final class EnsureCommunicationSetupActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    private FakeProvisioner $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->useFakeProvisioner();
    }

    public function testCompletingTheIntegrationAloneProvisionsEverything(): void
    {
        $integration = $this->integrateMessaging(['+18095550100']);

        // The durable marker commits with the integration, before any vendor call.
        $pending = $this->connectionFor($integration, '+18095550100');
        $this->assertSame(SetupStatusEnum::PENDING_SETUP, $pending->setup_status);
        $this->assertTrue(InboundClaim::query()->where('connection_id', $pending->getId())->exists());
        Queue::assertPushed(EnsureCommunicationSetupJob::class);

        $report = $this->runSetup($integration);
        $connection = $pending->fresh();
        $inbound = $this->receiver();

        $this->assertSame(SetupReport::READY, $report->outcome);
        $this->assertSame(SetupStatusEnum::READY, $connection->setup_status);
        $this->assertTrue($connection->enabled);
        $this->assertNotNull($connection->first_ready_at);
        $this->assertSame($inbound->getUrl(), $this->vendor->vendor['+18095550100']);
        $this->assertSame($connection->getId(), $this->smsDefault()?->connection_id);

        // Nothing else to do: the person can be messaged right away.
        $transport = app(FakeTransport::class);
        $delivery = $this->communication()
            ->send($this->personWithCellphone('+18095550199'), 'Hello', channels: ['sms'], sync: true)
            ->delivery(ChannelTypeEnum::SMS);

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertCount(1, $transport->sent);
    }

    public function testInstallingTheIntegrationSwitchesItsReceiverOnBeforeSetupRuns(): void
    {
        $this->integrateMessaging(['+18095550100']);

        $receiver = $this->receiver();
        $this->assertTrue((bool) $receiver->is_active);
        $this->assertSame('fake', $receiver->configuration['communication']['provider']);
    }

    public function testResavingSwitchesBackOnTheSameReceiver(): void
    {
        $integration = $this->integrateMessaging(['+18095550100']);
        $receiver = $this->receiver();
        $receiver->forceFill(['is_active' => false])->saveOrFail();

        $this->resaveIntegration($integration, ['+18095550100']);

        $this->assertSame($receiver->getId(), $this->receiver()->getId(), 'the URL the vendor has keeps working');
        $this->assertTrue((bool) $receiver->refresh()->is_active);
    }

    public function testSavingTheIntegrationAgainCreatesNothingNew(): void
    {
        $integration = $this->integrateMessaging(['+18095550100']);
        $this->runSetup($integration);

        $this->resaveIntegration($integration, ['+18095550100']);
        $this->resaveIntegration($integration, ['+18095550100']);
        $this->runSetup($integration);

        $this->assertSame(1, Connection::query()->where('integrations_company_id', $integration->getId())->count());
        $this->assertSame(1, $this->receivers());
    }

    public function testChangingNumbersFromABToBC(): void
    {
        $integration = $this->integrateMessaging(['+18095550101', '+18095550102']);
        $this->runSetup($integration);
        $a = $this->connectionFor($integration, '+18095550101');
        $b = $this->connectionFor($integration, '+18095550102');

        $queued = $this->queuedDeliveryOn($a);

        $this->resaveIntegration($integration, ['+18095550102', '+18095550103']);
        $this->runSetup($integration);
        $c = $this->connectionFor($integration, '+18095550103');

        // A is torn down: nothing more leaves through it, its number is still owned until cleanup.
        $this->assertSame(SetupStatusEnum::DISCONNECTING, $a->fresh()->setup_status);
        $this->assertSame(DeliveryStatusEnum::SKIPPED, $queued->fresh()->status);
        $this->assertTrue(InboundClaim::query()->where('connection_id', $a->getId())->exists());

        new RunConnectionCleanupAction($a->fresh())->execute();

        $this->assertSame(SetupStatusEnum::DISCONNECTED, $a->fresh()->setup_status);
        $this->assertArrayNotHasKey('+18095550101', $this->vendor->vendor);
        $this->assertFalse(InboundClaim::query()->where('connection_id', $a->getId())->exists());

        // B is the same row it was; C is new and ready.
        $this->assertSame(SetupStatusEnum::READY, $b->fresh()->setup_status);
        $this->assertSame(SetupStatusEnum::READY, $c->setup_status);

        $before = Connection::query()->where('integrations_company_id', $integration->getId())->count();
        $this->runSetup($integration);
        $this->assertSame($before, Connection::query()->where('integrations_company_id', $integration->getId())->count());
    }

    public function testRetiredDefaultMovesWithinIntegration(): void
    {
        $integration = $this->integrateMessaging([
            ['phone_number' => '+18095550111', 'is_default' => true],
            '+18095550112',
        ]);
        $this->runSetup($integration);
        $this->assertSame($this->connectionFor($integration, '+18095550111')->getId(), $this->smsDefault()->connection_id);

        $this->resaveIntegration($integration, ['+18095550112']);
        $this->runSetup($integration);

        $this->assertSame($this->connectionFor($integration, '+18095550112')->getId(), $this->smsDefault()->connection_id);
    }

    public function testSameNumberInAnotherWorkspaceIsBlockedWhileClaimed(): void
    {
        $first = $this->integrateMessaging(['+18095550120']);
        $this->runSetup($first);

        $second = $this->integrateMessaging(['+18095550120'], $this->otherCompany());
        $report = $this->runSetup($second);

        $this->assertSame(SetupReport::BLOCKED, $report->outcome);
        $this->assertStringContainsString('claimed_by_another_workspace', (string) $report->reason);
        $this->assertSame(SetupStatusEnum::BLOCKED, $this->connectionFor($second, '+18095550120')->setup_status);
    }

    public function testClaimHeldWhileDisconnectingBlocksOtherSignup(): void
    {
        $first = $this->integrateMessaging(['+18095550121']);
        $this->runSetup($first);
        $this->resaveIntegration($first, []);
        $this->runSetup($first);

        $second = $this->integrateMessaging(['+18095550121'], $this->otherCompany());

        $this->assertSame(SetupReport::BLOCKED, $this->runSetup($second)->outcome);
    }

    public function testDisconnectReleasesClaimAndSecondWorkspaceCanConnect(): void
    {
        $first = $this->integrateMessaging(['+18095550122']);
        $this->runSetup($first);
        $this->resaveIntegration($first, []);
        $this->runSetup($first);
        new RunConnectionCleanupAction($this->connectionFor($first, '+18095550122'))->execute();

        $second = $this->integrateMessaging(['+18095550122'], $this->otherCompany());

        $this->assertSame(SetupReport::READY, $this->runSetup($second)->outcome);
    }

    public function testConnectDisconnectReconnectCreatesNewRowKeepsHistory(): void
    {
        $integration = $this->integrateMessaging(['+18095550130']);
        $this->runSetup($integration);
        $old = $this->connectionFor($integration, '+18095550130');

        $this->resaveIntegration($integration, []);
        $this->runSetup($integration);
        new RunConnectionCleanupAction($old->fresh())->execute();

        $this->resaveIntegration($integration, ['+18095550130']);
        $this->runSetup($integration);
        $new = $this->connectionFor($integration, '+18095550130');

        $this->assertNotSame($old->getId(), $new->getId());
        $this->assertSame(SetupStatusEnum::DISCONNECTED, $old->fresh()->setup_status);
        $this->assertSame(SetupStatusEnum::READY, $new->setup_status);
    }

    /**
     * Saving the integration replaces its selection; a sweep never does. A number the person
     * disconnected stays disconnected through any number of sweeps until they select it again.
     */
    public function testDisconnectWhileStillSelectedThenRepeatedSweepsRecreateNothing(): void
    {
        $integration = $this->integrateMessaging(['+18095550131']);
        $this->runSetup($integration);
        $connection = $this->connectionFor($integration, '+18095550131');

        new RetireConnectionAction($connection, 'disconnected_by_user')->execute();
        new RunConnectionCleanupAction($connection->fresh())->execute();

        $this->runSetup($integration);
        $this->runSetup($integration);

        $this->assertSame(0, Connection::query()->live()->where('integrations_company_id', $integration->getId())->count());

        $this->resaveIntegration($integration->fresh(), ['+18095550131']);
        $this->runSetup($integration);

        $this->assertSame(1, Connection::query()->live()->where('integrations_company_id', $integration->getId())->count());
    }

    public function testVendorRegistrationFailsThenSweepCompletesSetup(): void
    {
        $this->vendor->failRegistrations = 1;
        $integration = $this->integrateMessaging(['+18095550140']);

        $this->assertSame(SetupReport::FAILED, $this->runSetup($integration)->outcome);
        $failed = $this->connectionFor($integration, '+18095550140');
        $this->assertSame(SetupStatusEnum::FAILED, $failed->setup_status);
        $this->assertNotNull($failed->next_setup_at);

        // The sweep respects the backoff, then completes setup once it has passed.
        $this->runSetup($integration, respectBackoff: true);
        $this->assertSame(SetupStatusEnum::FAILED, $failed->fresh()->setup_status);

        $this->travel(2)->minutes();
        $this->runSetup($integration, respectBackoff: true);

        $this->assertSame(SetupStatusEnum::READY, $failed->fresh()->setup_status);
        $this->assertTrue($failed->fresh()->enabled);
    }

    public function testAMissingPrerequisiteIsBlockedNotReady(): void
    {
        $this->vendor->missingPrerequisite = 'a2p_registration_pending';
        $integration = $this->integrateMessaging(['+18095550141']);

        $report = $this->runSetup($integration);

        $this->assertSame(SetupReport::BLOCKED, $report->outcome);
        $this->assertFalse($this->connectionFor($integration, '+18095550141')->enabled);

        $this->vendor->missingPrerequisite = null;
        $this->runSetup($integration);

        $this->assertSame(SetupStatusEnum::READY, $this->connectionFor($integration, '+18095550141')->setup_status);
    }

    public function testAForeignRegistrationIsBlockedNotReplaced(): void
    {
        $this->vendor->vendor['+18095550142'] = 'https://somebody-else.example.com/sms';
        $integration = $this->integrateMessaging(['+18095550142']);

        $report = $this->runSetup($integration);

        $this->assertSame(SetupReport::BLOCKED, $report->outcome);
        $this->assertStringContainsString('foreign_registration', (string) $report->reason);
        $this->assertSame('https://somebody-else.example.com/sms', $this->vendor->vendor['+18095550142']);
    }

    public function testRepairKeepsDisabledPendingAndVersion(): void
    {
        $integration = $this->integrateMessaging(['+18095550150']);
        $this->runSetup($integration);
        $connection = $this->connectionFor($integration, '+18095550150');
        $queued = $this->queuedDeliveryOn($connection);
        $connection->forceFill(['enabled' => false])->saveOrFail();
        $version = $connection->activation_version;

        unset($this->vendor->vendor['+18095550150']);
        $this->runSetup($integration);

        $repaired = $connection->fresh();
        $this->assertFalse($repaired->enabled);
        $this->assertSame($version, $repaired->activation_version);
        $this->assertSame(DeliveryStatusEnum::PENDING, $queued->fresh()->status);
        $this->assertArrayHasKey('+18095550150', $this->vendor->vendor, 'the drifted registration is corrected');
    }

    public function testSharedResourceSurvivesRetiringOneDependent(): void
    {
        $this->vendor->services = ['+18095550160' => 'MG-1', '+18095550161' => 'MG-1'];
        $integration = $this->integrateMessaging(['+18095550160', '+18095550161']);
        $this->runSetup($integration);

        $this->resaveIntegration($integration, ['+18095550161']);
        $this->runSetup($integration);
        new RunConnectionCleanupAction($this->connectionFor($integration, '+18095550160'))->execute();
        new ReleaseUnusedRegistrationsAction($this->currentApp())->execute();

        $this->assertArrayHasKey('MG-1', $this->vendor->vendor, 'the other number still answers through the service');
    }

    public function testAMovedNumbersOldCleanupNeverRemovesTheNewOwnersRegistration(): void
    {
        $first = $this->integrateMessaging(['+18095550170']);
        $this->runSetup($first);
        $old = $this->connectionFor($first, '+18095550170');

        $this->resaveIntegration($first, []);
        $this->runSetup($first);

        // The vendor was down for the whole bounded wait: the claim is released with work left.
        $old->fresh()->cleanupTasks()->update(['attempts' => 6, 'next_attempt_at' => now()->addHour()]);
        new RunConnectionCleanupAction($old->fresh())->execute();
        $this->assertFalse(InboundClaim::query()->where('connection_id', $old->getId())->exists());

        // Another workspace takes the number and registers its own receiver.
        $second = $this->integrateMessaging(['+18095550170'], $this->otherCompany());
        $this->runSetup($second);
        $theirs = $this->vendor->vendor['+18095550170'];

        // The old cleanup finally runs: it finds a value that is not its own and touches nothing.
        $old->fresh()->cleanupTasks()->update(['next_attempt_at' => now()]);
        new RunConnectionCleanupAction($old->fresh())->execute();

        $this->assertSame($theirs, $this->vendor->vendor['+18095550170']);
        $this->assertSame(CleanupTaskStatusEnum::SUPERSEDED, $old->fresh()->cleanupTasks()->first()->status);
    }

    public function testWorkerKilledDuringRetirementIsRecoveredBySweep(): void
    {
        $integration = $this->integrateMessaging(['+18095550180']);
        $this->runSetup($integration);
        $connection = $this->connectionFor($integration, '+18095550180');
        $this->resaveIntegration($integration, []);
        $this->runSetup($integration);

        // A worker took the task and died holding it.
        $connection->fresh()->cleanupTasks()->update([
            'status' => CleanupTaskStatusEnum::RUNNING->value,
            'lease_until' => now()->subMinute(),
        ]);

        $this->artisan('communication:setup', ['--app' => $this->currentApp()->getId()])->assertSuccessful();

        $this->assertSame(SetupStatusEnum::DISCONNECTED, $connection->fresh()->setup_status);
    }

    private function connectionFor(IntegrationsCompany $integration, string $number): Connection
    {
        return Connection::query()
            ->where('integrations_company_id', $integration->getId())
            ->where('account_key', $number)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function queuedDeliveryOn(Connection $connection): Delivery
    {
        Queue::fake();

        ChannelDefault::query()->updateOrCreate(
            ['apps_id' => $connection->apps_id, 'companies_id' => $connection->companies_id, 'channel' => 'sms'],
            ['connection_id' => $connection->getId()]
        );

        return $this->communication()
            ->send($this->personWithCellphone(), 'Hello', channels: ['sms'])
            ->delivery(ChannelTypeEnum::SMS);
    }

    private function smsDefault(): ?ChannelDefault
    {
        return ChannelDefault::query()
            ->fromApp($this->currentApp())
            ->fromCompany($this->currentCompany())
            ->where('channel', 'sms')
            ->first();
    }

    private function receiver(): ReceiverWebhook
    {
        return app(CommunicationReceivers::class)->find($this->currentApp(), $this->currentCompany(), 'fake')
            ?? $this->fail('Setup made no receiver.');
    }

    private function receivers(): int
    {
        return ReceiverWebhook::query()
            ->fromApp($this->currentApp())
            ->fromCompany($this->currentCompany())
            ->whereHas('action', fn ($query) => $query->where('model_name', ProcessCommunicationWebhookJob::class))
            ->count();
    }

    private function otherCompany(): Companies
    {
        return Companies::factory()->create();
    }
}
