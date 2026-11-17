<?php

declare(strict_types=1);

namespace Tests\Communication\Connections;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Connections\Actions\EnsureProviderIntegrationAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany as IntegrationsCompanyData;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeProvider;
use Tests\TestCase;

/**
 * The integration row an account gives a company when it comes to exist without the integration
 * form: one row per company and provider, whichever region it sits on.
 */
final class EnsureProviderIntegrationActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'inventory'];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->useFakeProvider();
    }

    public function testACompanyWithoutTheIntegrationIsGivenItWithItsSelection(): void
    {
        $integration = $this->catalogRow();
        $this->region($this->currentCompany());

        $row = $this->ensure(['phone_number' => '+18095550950']);

        $this->assertSame($integration->getId(), (int) $row->integrations_id);
        $this->assertSame($this->currentApp()->getId(), (int) $row->apps_id);
        $this->assertSame('+18095550950', $row->config['phone_number']);
    }

    public function testARowOnAnotherRegionIsReusedAndLearnsTheSelection(): void
    {
        $integration = $this->catalogRow();
        $this->region($this->currentCompany());
        $elsewhere = Regions::create([
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $this->currentCompany()->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 0,
            'is_deleted' => 0,
        ]);
        $existing = new CreateIntegrationCompanyAction(
            new IntegrationsCompanyData(
                app: $this->currentApp(),
                integration: $integration,
                company: $this->currentCompany(),
                region: $elsewhere,
                config: [],
                persistedConfig: ['api_key' => 'kept']
            ),
            auth()->user(),
            $this->activeStatus()
        )->execute();

        $row = $this->ensure(['agent_id' => 7]);

        $this->assertTrue($row->is($existing));
        $this->assertSame(['api_key' => 'kept', 'agent_id' => 7], $row->fresh()->config);
        $this->assertSame(
            1,
            IntegrationsCompany::query()
                ->fromApp($this->currentApp())
                ->fromCompany($this->currentCompany())
                ->where('integrations_id', $integration->getId())
                ->count()
        );
    }

    public function testAProviderMissingFromTheCatalogGivesNoRow(): void
    {
        $this->assertNull($this->ensure());
    }

    /**
     * @param array<string, mixed> $selection
     */
    private function ensure(array $selection = []): ?IntegrationsCompany
    {
        return new EnsureProviderIntegrationAction(
            $this->currentApp(),
            $this->currentCompany(),
            auth()->user(),
            FakeProvider::class,
            $selection
        )->execute();
    }

    private function catalogRow(): Integrations
    {
        return Integrations::create([
            'apps_id' => 0,
            'name' => uniqid('messaging_'),
            'handler' => FakeProvider::class,
        ]);
    }
}
