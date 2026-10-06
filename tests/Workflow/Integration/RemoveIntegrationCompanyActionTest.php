<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\RemoveIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Tests\Workflow\Integration\Fixtures\SettingsOwningHandler;

final class RemoveIntegrationCompanyActionTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = [null, 'workflow', 'inventory'];

    private Apps $kanvasApp;
    private Companies $company;
    private Regions $region;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $user = auth()->user();
        $this->company = $user->getCurrentCompany();
        $this->region = $this->createDefaultRegion($this->company, $this->kanvasApp, $user);
    }

    protected function tearDown(): void
    {
        $this->company->del(SettingsOwningHandler::COMPANY_KEY);
        $this->kanvasApp->del(SettingsOwningHandler::APP_KEY);

        parent::tearDown();
    }

    /**
     * Connector code reads credentials straight from company settings, so a removed row whose
     * keys survive is an integration that is still live.
     */
    public function testRemovingDeletesTheCompanySettingsTheHandlerWrote(): void
    {
        $integrationCompany = $this->connect($this->company, $this->integrationWithHandler());

        $this->assertSame('company-secret', $this->company->get(SettingsOwningHandler::COMPANY_KEY));

        $this->assertTrue(new RemoveIntegrationCompanyAction($integrationCompany, $this->kanvasApp)->execute());

        $this->assertNull($this->company->get(SettingsOwningHandler::COMPANY_KEY));
        $this->assertTrue(
            IntegrationsCompany::query()->whereKey($integrationCompany->getKey())->notDeleted()->doesntExist()
        );
    }

    /**
     * App settings are shared by every company on the app; one company disconnecting must not
     * touch them.
     */
    public function testRemovingLeavesAppSettingsAlone(): void
    {
        $integrationCompany = $this->connect($this->company, $this->integrationWithHandler());

        new RemoveIntegrationCompanyAction($integrationCompany, $this->kanvasApp)->execute();

        $this->assertSame('app-secret', $this->kanvasApp->get(SettingsOwningHandler::APP_KEY));
    }

    public function testRemovingOneCompanyLeavesAnotherCompanyConnected(): void
    {
        $integration = $this->integrationWithHandler();
        $first = $this->connect($this->company, $integration);

        $otherCompany = Companies::factory()->create();
        $otherCompany->associateApp($this->kanvasApp);
        $second = $this->connect($otherCompany, $integration);

        new RemoveIntegrationCompanyAction($first, $this->kanvasApp)->execute();

        $this->assertNull($this->company->get(SettingsOwningHandler::COMPANY_KEY));
        $this->assertSame('company-secret', $otherCompany->get(SettingsOwningHandler::COMPANY_KEY));
        $this->assertTrue(IntegrationsCompany::query()->whereKey($second->getKey())->notDeleted()->exists());

        $otherCompany->del(SettingsOwningHandler::COMPANY_KEY);
    }

    /**
     * Catalog rows seeded without a real handler (and test rows using 'none') must still be removable.
     */
    public function testARowWhoseHandlerIsNotAClassIsStillRemoved(): void
    {
        $integration = Integrations::create([
            'apps_id' => $this->kanvasApp->getId(),
            'name' => uniqid('remove_no_handler_'),
            'handler' => 'none',
            'type' => 'key',
        ]);
        $integrationCompany = $this->createRow($this->company, $integration);

        $this->assertTrue(new RemoveIntegrationCompanyAction($integrationCompany, $this->kanvasApp)->execute());
        $this->assertTrue(
            IntegrationsCompany::query()->whereKey($integrationCompany->getKey())->notDeleted()->doesntExist()
        );
    }

    private function integrationWithHandler(): Integrations
    {
        return Integrations::create([
            'apps_id' => $this->kanvasApp->getId(),
            'name' => uniqid('remove_integration_'),
            'handler' => SettingsOwningHandler::class,
            'type' => 'key',
        ]);
    }

    private function connect(Companies $company, Integrations $integration): IntegrationsCompany
    {
        new SettingsOwningHandler(
            app: $this->kanvasApp,
            company: $company,
            region: $this->region,
            data: [],
            integration: $integration,
        )->setup();

        return $this->createRow($company, $integration);
    }

    private function createRow(Companies $company, Integrations $integration): IntegrationsCompany
    {
        $status = Status::where('slug', StatusEnum::ACTIVE->value)
            ->where('apps_id', 0)
            ->firstOrFail();

        return IntegrationsCompany::create([
            'companies_id' => $company->getId(),
            'integrations_id' => $integration->getId(),
            'region_id' => $this->region->getId(),
            'status_id' => $status->getId(),
        ]);
    }
}
