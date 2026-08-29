<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Traits\ActivityIntegrationTrait;
use Tests\TestCase;

final class ActivityIntegrationTraitTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['workflow', 'ecosystem'];

    public function testGetIntegrationCompanyResolvesWhenRegionIsGlobal(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $globalRegion = $this->createRegion($app, companiesId: 0);

        // The regression: a global region has no owning company, so deriving the
        // company from the region yields null and fatals on getByIntegration().
        $this->assertNull($globalRegion->company);

        $integrationCompany = $this->registerIntegration($company->getId(), $globalRegion);

        $resolved = $this->activity()->getIntegrationCompany(
            IntegrationsEnum::INTERNAL,
            $globalRegion,
            $this->activeStatus(),
            $company
        );

        $this->assertNotNull($resolved);
        $this->assertEquals($integrationCompany->getId(), $resolved->getId());
    }

    public function testGetIntegrationCompanyStillResolvesWhenRegionIsCompanyScoped(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $region = $this->createRegion($app, companiesId: $company->getId());
        $integrationCompany = $this->registerIntegration($company->getId(), $region);

        $resolved = $this->activity()->getIntegrationCompany(
            IntegrationsEnum::INTERNAL,
            $region,
            $this->activeStatus(),
            $company
        );

        $this->assertNotNull($resolved);
        $this->assertEquals($integrationCompany->getId(), $resolved->getId());
    }

    public function testGetIntegrationCompanyDoesNotLeakAcrossCompanies(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $globalRegion = $this->createRegion($app, companiesId: 0);
        $this->registerIntegration($company->getId(), $globalRegion);

        $otherCompany = clone $company;
        $otherCompany->id = $company->getId() + 100000;

        $resolved = $this->activity()->getIntegrationCompany(
            IntegrationsEnum::INTERNAL,
            $globalRegion,
            $this->activeStatus(),
            $otherCompany
        );

        $this->assertNull($resolved);
    }

    public function testGetIntegrationCompanyFallsBackToRegionCompanyWhenNoneGiven(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $region = $this->createRegion($app, companiesId: $company->getId());
        $integrationCompany = $this->registerIntegration($company->getId(), $region);

        $resolved = $this->activity()->getIntegrationCompany(
            IntegrationsEnum::INTERNAL,
            $region,
            $this->activeStatus()
        );

        $this->assertNotNull($resolved);
        $this->assertEquals($integrationCompany->getId(), $resolved->getId());
    }

    public function testGetIntegrationCompanyReturnsNullWhenGlobalRegionAndNoCompanyGiven(): void
    {
        $app = app(Apps::class);
        $globalRegion = $this->createRegion($app, companiesId: 0);

        $resolved = $this->activity()->getIntegrationCompany(
            IntegrationsEnum::INTERNAL,
            $globalRegion,
            $this->activeStatus()
        );

        $this->assertNull($resolved);
    }

    public function testExecuteIntegrationStoresWorkflowInputInHistory(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $region = $this->createRegion($app, companiesId: $company->getId());
        $this->registerIntegration($company->getId(), $region);
        $input = [
            'message_id' => 123,
            'payload' => ['source' => 'workflow-test'],
        ];

        $this->activity()->executeIntegration(
            entity: $company,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: fn ($entity, $app, $integrationCompany, $params) => $params,
            additionalParams: $input,
            region: $region,
            company: $company,
        );

        $history = EntityIntegrationHistory::query()
            ->where('entity_namespace', $company::class)
            ->where('entity_id', $company->getId())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($company->toArray(), $history->input['entity']);
        $this->assertSame($input, $history->input['params']);
    }

    private function createRegion(Apps $app, int $companiesId): Regions
    {
        return Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => $companiesId,
            'users_id' => 0,
            'currency_id' => Currencies::getBaseCurrency()->getId(),
            'name' => 'Region ' . uniqid(),
            'is_default' => 0,
            'is_deleted' => 0,
        ]);
    }

    private function registerIntegration(int $companiesId, Regions $region): IntegrationsCompany
    {
        return IntegrationsCompany::create([
            'companies_id' => $companiesId,
            'integrations_id' => Integrations::getByName(IntegrationsEnum::INTERNAL->value)->getId(),
            'region_id' => $region->getId(),
            'status_id' => $this->activeStatus()->getId(),
            'is_active' => 1,
        ]);
    }

    private function activeStatus(): Status
    {
        return Status::where('slug', StatusEnum::ACTIVE->value)
            ->where('apps_id', 0)
            ->firstOrFail();
    }

    private function activity(): object
    {
        return new class () {
            use ActivityIntegrationTrait;

            public function workflowId(): ?int
            {
                return null;
            }
        };
    }
}
