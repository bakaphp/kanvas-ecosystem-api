<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Tests\TestCase;

final class RegionDefaultGlobalFallbackTest extends TestCase
{
    private Apps $currentApp;
    private mixed $originalFlag = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currentApp = app(Apps::class);
        $this->originalFlag = $this->currentApp->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);
    }

    protected function tearDown(): void
    {
        $this->currentApp->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, $this->originalFlag);
        parent::tearDown();
    }

    private function createCompanyWithoutRegions(): Companies
    {
        $company = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        Regions::where('companies_id', $company->getId())->update(['is_deleted' => 1]);

        return $company;
    }

    private function createGlobalDefaultRegion(): Regions
    {
        return Regions::create([
            'companies_id' => 0,
            'apps_id' => $this->currentApp->getId(),
            'currency_id' => 1,
            'name' => fake()->unique()->city() . ' Global Region',
            'is_default' => 1,
            'is_deleted' => 0,
        ]);
    }

    public function testFallsBackToGlobalDefaultWhenFlagEnabled(): void
    {
        $this->currentApp->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 1);
        $company = $this->createCompanyWithoutRegions();
        $globalRegion = $this->createGlobalDefaultRegion();

        $default = Regions::getDefault($company, $this->currentApp);

        $this->assertNotNull($default);
        $this->assertEquals($globalRegion->getId(), $default->getId());
    }

    public function testDoesNotFallBackToGlobalWhenFlagDisabled(): void
    {
        $this->currentApp->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 0);
        $company = $this->createCompanyWithoutRegions();
        $this->createGlobalDefaultRegion();

        $this->assertNull(Regions::getDefault($company, $this->currentApp));
    }

    public function testCompanyDefaultRegionWinsOverGlobal(): void
    {
        $this->currentApp->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 1);
        $company = $this->createCompanyWithoutRegions();
        $this->createGlobalDefaultRegion();

        $companyRegion = Regions::create([
            'companies_id' => $company->getId(),
            'apps_id' => $this->currentApp->getId(),
            'currency_id' => 1,
            'name' => fake()->unique()->city() . ' Company Region',
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        $default = Regions::getDefault($company, $this->currentApp);

        $this->assertEquals($companyRegion->getId(), $default->getId());
    }
}
