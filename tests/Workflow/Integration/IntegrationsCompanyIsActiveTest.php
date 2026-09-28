<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany as IntegrationsCompanyData;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class IntegrationsCompanyIsActiveTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = [null, 'workflow', 'inventory'];

    private Regions $region;
    private Integrations $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();

        $this->region = $this->createDefaultRegion($user->getCurrentCompany(), $app, $user);
        $this->integration = Integrations::create([
            'apps_id' => $app->getId(),
            'name' => uniqid('is_active_regression_'),
            'handler' => 'none',
            'type' => 'key',
        ]);
    }

    /**
     * Regression for KANVAS-ECOSYSTEM-5GS: the row was inserted without is_active, MySQL applied the
     * column default, and the returned in-memory model had no attribute at all — so the mutation's
     * `is_active: Boolean!` resolved to null and blew up with an InvariantViolation.
     */
    public function testNewIntegrationCompanyCarriesIsActiveWithoutRefetching(): void
    {
        $integrationCompany = $this->createIntegrationCompany();

        $this->assertTrue($integrationCompany->is_active);
        $this->assertTrue($integrationCompany->fresh()->is_active);
    }

    public function testCreatingAgainDoesNotReactivateADeactivatedIntegration(): void
    {
        $this->createIntegrationCompany()->isActive(false);

        $this->assertFalse($this->createIntegrationCompany()->is_active);
    }

    /**
     * The default must stay a default — a caller that asks for an inactive row gets one.
     */
    public function testAnExplicitIsActiveBeatsTheDefault(): void
    {
        $integrationCompany = IntegrationsCompany::create([
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'integrations_id' => $this->integration->getId(),
            'region_id' => $this->region->getId(),
            'status_id' => $this->activeStatus()->getId(),
            'is_active' => false,
        ]);

        $this->assertFalse($integrationCompany->is_active);
        $this->assertFalse($integrationCompany->fresh()->is_active);
    }

    private function createIntegrationCompany(): IntegrationsCompany
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return new CreateIntegrationCompanyAction(
            new IntegrationsCompanyData(
                app: $app,
                integration: $this->integration,
                company: $user->getCurrentCompany(),
                region: $this->region,
                config: []
            ),
            $user,
            $this->activeStatus()
        )->execute();
    }

    private function activeStatus(): Status
    {
        return Status::where('slug', StatusEnum::ACTIVE->value)
            ->where('apps_id', 0)
            ->firstOrFail();
    }
}
