<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum as MovipassCustomFieldEnum;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum;
use Kanvas\Regions\Models\Regions;
use Kanvas\Regions\Services\RegionResolutionService;
use Tests\TestCase;

final class CompanyDefaultRegionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Apps $appInstance;
    private Companies $company;
    private RegionResolutionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass region tests are skipped in CI');
        }

        $this->appInstance = app(Apps::class);
        $this->company = Auth::user()->getCurrentCompany();
        $this->service = new RegionResolutionService($this->appInstance);

        $this->company->del(CustomFieldEnum::DEFAULT_REGION_ID->value);
    }

    protected function tearDown(): void
    {
        // setUp() can markTestSkipped() before $company is assigned; tearDown still runs
        if (isset($this->company)) {
            $this->company->del(CustomFieldEnum::DEFAULT_REGION_ID->value);
        }

        parent::tearDown();
    }

    public function testResolvesGlobalRegionAssignedToCompany(): void
    {
        $global = $this->makeGlobalRegion('test-company-default-sv', 'TCDSV');

        $this->company->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $global->getId());

        $resolved = $this->service->forCompany($this->company);

        $this->assertNotNull($resolved);
        $this->assertEquals($global->getId(), $resolved->getId());
        $this->assertEquals(0, (int) $resolved->companies_id);
    }

    public function testFallsBackToDefaultWhenCustomFieldNotSet(): void
    {
        $resolved = $this->service->forCompany($this->company);
        $expected = Regions::getDefault($this->company, $this->appInstance);

        $this->assertEquals($expected?->getId(), $resolved?->getId());
    }

    public function testIgnoresRegionOutsideCompanyAndGlobalScope(): void
    {
        $foreign = $this->makeGlobalRegion('test-company-default-foreign', 'TCDFO');
        $foreign->companies_id = $this->company->getId() + 999999;
        $foreign->saveOrFail();

        $this->company->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $foreign->getId());

        $resolved = $this->service->forCompany($this->company);
        $expected = Regions::getDefault($this->company, $this->appInstance);

        $this->assertEquals($expected?->getId(), $resolved?->getId());
        $this->assertNotEquals($foreign->getId(), $resolved?->getId());
    }

    public function testBackfillCopiesLegacyMovipassRegionId(): void
    {
        $global = $this->makeGlobalRegion('test-company-default-backfill', 'TCDBF');

        $this->company->set(MovipassCustomFieldEnum::COMPANY_REGION_ID->value, $global->getId());

        $this->artisan('kanvas-movipass:backfill-company-default-region', [
            'app_id' => $this->appInstance->getId(),
            '--company_id' => $this->company->getId(),
        ])->assertExitCode(0);

        $this->assertEquals(
            $global->getId(),
            (int) $this->company->get(CustomFieldEnum::DEFAULT_REGION_ID->value)
        );

        $this->company->del(MovipassCustomFieldEnum::COMPANY_REGION_ID->value);
    }

    private function makeGlobalRegion(string $slug, string $shortSlug): Regions
    {
        $region = new Regions();
        $region->apps_id = $this->appInstance->getId();
        $region->companies_id = 0;
        $region->users_id = 0;
        $region->name = $slug;
        $region->slug = $slug;
        $region->short_slug = $shortSlug;
        $region->currency_id = Currencies::getByCode('USD')->getId();
        $region->is_default = 0;
        $region->saveOrFail();

        return $region;
    }
}
