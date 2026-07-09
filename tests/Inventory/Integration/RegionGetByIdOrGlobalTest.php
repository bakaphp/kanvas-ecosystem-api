<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Tests\TestCase;

final class RegionGetByIdOrGlobalTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['ecosystem', 'inventory'];

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

    private function createCompany(): Companies
    {
        return Companies::factory()->create(['users_id' => auth()->user()->getId()]);
    }

    private function createGlobalRegion(): Regions
    {
        return Regions::create([
            'companies_id' => 0,
            'apps_id' => $this->currentApp->getId(),
            'currency_id' => 1,
            'name' => fake()->unique()->city() . ' Global Region',
            'is_default' => 0,
            'is_deleted' => 0,
        ]);
    }

    public function testResolvesGlobalRegionEvenWhenFlagDisabled(): void
    {
        // The variants flag OFF is the exact condition under which the old getById() threw
        // "No query results for [Regions]" during integration setup.
        $this->currentApp->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 0);
        $company = $this->createCompany();
        $global = $this->createGlobalRegion();

        $resolved = RegionRepository::getByIdOrGlobal($global->getId(), $company, $this->currentApp);

        $this->assertSame($global->getId(), $resolved->getId());
        $this->assertSame(0, (int) $resolved->companies_id);
    }

    public function testResolvesCompanyOwnedRegion(): void
    {
        $company = $this->createCompany();
        $companyRegion = Regions::create([
            'companies_id' => $company->getId(),
            'apps_id' => $this->currentApp->getId(),
            'currency_id' => 1,
            'name' => fake()->unique()->city() . ' Company Region',
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        $resolved = RegionRepository::getByIdOrGlobal($companyRegion->getId(), $company, $this->currentApp);

        $this->assertSame($companyRegion->getId(), $resolved->getId());
        $this->assertSame($company->getId(), (int) $resolved->companies_id);
    }

    public function testThrowsWhenRegionBelongsToAnotherCompany(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $otherRegion = Regions::create([
            'companies_id' => $otherCompany->getId(),
            'apps_id' => $this->currentApp->getId(),
            'currency_id' => 1,
            'name' => fake()->unique()->city() . ' Other Region',
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        $this->expectException(ModelNotFoundException::class);
        RegionRepository::getByIdOrGlobal($otherRegion->getId(), $company, $this->currentApp);
    }
}
