<?php

declare(strict_types=1);

namespace Tests\Communication\Support;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\RefreshAgentConnectionsAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Support\ProviderRollout;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\Integrations;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeProvider;
use Tests\TestCase;

/**
 * The one answer to "does this domain provision this integration", for setup, for the save hook and
 * for whatever else would otherwise point the vendor's webhook somewhere.
 */
final class ProviderRolloutTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeProvisioner();
    }

    public function testAMessagingIntegrationOfTheAppIsProvisionedWithItsProvider(): void
    {
        $integration = $this->integrateMessaging(['+18095550201']);

        $this->assertSame(FakeProvider::class, new ProviderRollout()->providerFor($integration, $this->currentApp()));
    }

    public function testAnIntegrationThatCarriesNoMessagesIsLeftAlone(): void
    {
        $integration = $this->integrateMessaging(['+18095550202']);
        Integrations::query()->whereKey($integration->integrations_id)->update(['handler' => self::class]);

        $this->assertNull(new ProviderRollout()->providerFor($integration->fresh(), $this->currentApp()));
    }

    public function testAnotherAppsIntegrationIsLeftAlone(): void
    {
        $integration = $this->integrateMessaging(['+18095550203']);
        $anotherApp = new Apps()->forceFill(['id' => PHP_INT_MAX]);

        $this->assertNull(new ProviderRollout()->providerFor($integration, $anotherApp));
    }

    public function testAHeldBackProviderIsNotProvisionedUntilTheTenantOptsIn(): void
    {
        $integration = $this->integrateMessaging(['+18095550204']);
        config(['communication.opt_in' => ['fake']]);

        $this->assertNull(new ProviderRollout()->providerFor($integration, $this->currentApp()));
        $this->assertFalse($this->manages(FakeProvider::integration()));
    }

    public function testACompanyIsManagedByTheIntegrationItCompleted(): void
    {
        $this->assertFalse($this->manages(FakeProvider::integration()));

        $this->integrateMessaging(['+18095550205']);

        $this->assertTrue($this->manages(FakeProvider::integration()));
        $this->assertFalse($this->manages(IntegrationsEnum::TWILIO));
        $this->assertFalse(
            new ProviderRollout()->manages(FakeProvider::integration(), $this->currentApp(), $this->otherCompany()),
            'another company of the app integrated nothing'
        );
    }

    public function testRefreshingAnAgentReconcilesEveryManagedIntegrationOfItsCompany(): void
    {
        $integration = $this->integrateMessaging(['+18095550207']);
        Connection::query()->ofIntegration($integration)->delete();

        $this->assertSame(['fake'], new RefreshAgentConnectionsAction($this->agent())->execute());
        $this->assertSame(1, Connection::query()->live()->ofIntegration($integration)->count());
    }

    private function manages(IntegrationsEnum $integration): bool
    {
        return new ProviderRollout()->manages($integration, $this->currentApp(), $this->currentCompany());
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create();
    }
}
