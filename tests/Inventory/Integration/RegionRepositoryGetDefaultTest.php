<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Tests\TestCase;

final class RegionRepositoryGetDefaultTest extends TestCase
{
    private function createCompanyWithoutRegions(): Companies
    {
        $company = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        Regions::where('companies_id', $company->getId())->update(['is_deleted' => 1]);

        return $company;
    }

    public function testResolvesGlobalDefaultRegionWhenCompanyHasNone(): void
    {
        $app = app(Apps::class);
        $company = $this->createCompanyWithoutRegions();

        $globalRegion = Regions::create([
            'companies_id' => 0,
            'apps_id' => $app->getId(),
            'currency_id' => 1,
            'name' => fake()->unique()->city() . ' Global Region',
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        $default = RegionRepository::getDefault($company, $app);

        $this->assertEquals($globalRegion->getId(), $default->getId());
    }

    public function testCompanyDefaultRegionWinsOverGlobal(): void
    {
        $app = app(Apps::class);
        $company = $this->createCompanyWithoutRegions();

        Regions::create([
            'companies_id' => 0,
            'apps_id' => $app->getId(),
            'currency_id' => 1,
            'name' => fake()->unique()->city() . ' Global Region',
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        $companyRegion = Regions::create([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'currency_id' => 1,
            'name' => fake()->unique()->city() . ' Company Region',
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        $default = RegionRepository::getDefault($company, $app);

        $this->assertEquals($companyRegion->getId(), $default->getId());
    }

    public function testGlobalDefaultRegionFromAnotherAppIsNotResolved(): void
    {
        $app = app(Apps::class);
        $otherApp = Apps::where('id', '!=', $app->getId())->first();

        if ($otherApp === null) {
            $this->markTestSkipped('No second app available to exercise the app filter');
        }

        $company = $this->createCompanyWithoutRegions();

        $foreignRegion = Regions::create([
            'companies_id' => 0,
            'apps_id' => $otherApp->getId(),
            'currency_id' => 1,
            'name' => fake()->unique()->city() . ' Foreign Global Region',
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        try {
            $default = RegionRepository::getDefault($company, $app);
            $this->assertNotEquals($foreignRegion->getId(), $default->getId());
        } catch (ModelNotFoundException) {
            // No default region in the current app at all — the app filter held.
            $this->addToAssertionCount(1);
        }
    }
}
