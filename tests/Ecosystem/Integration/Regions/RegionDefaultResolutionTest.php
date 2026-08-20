<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Regions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum;
use Kanvas\Inventory\Regions\Models\Regions as InventoryRegions;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class RegionDefaultResolutionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem'];

    private Apps $appInstance;
    private Companies $company;

    /**
     * What getDefault() answers with no custom field set, i.e. the pre-existing is_default row. The
     * tenant fixture already owns one and its id is not knowable here, so the flag branch is asserted
     * against this rather than against a region the test creates.
     */
    private ?Regions $flagDefault = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appInstance = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->company->del(CustomFieldEnum::DEFAULT_REGION_ID->value);

        $this->flagDefault = Regions::getDefault($this->company, $this->appInstance);
    }

    protected function tearDown(): void
    {
        if (isset($this->company)) {
            $this->company->del(CustomFieldEnum::DEFAULT_REGION_ID->value);
        }

        parent::tearDown();
    }

    public function testResolvesGlobalRegionPointedAtByTheCompanyCustomField(): void
    {
        $global = $this->createRegion(companiesId: 0);
        $this->company->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $global->getId());

        $resolved = Regions::getDefault($this->company, $this->appInstance);

        $this->assertNotNull($resolved);
        $this->assertEquals($global->getId(), $resolved->getId());
        $this->assertEquals(0, (int) $resolved->companies_id);
    }

    public function testCustomFieldWinsOverTheFlagBasedDefault(): void
    {
        $chosen = $this->createRegion(companiesId: $this->company->getId());
        $this->company->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $chosen->getId());

        $resolved = Regions::getDefault($this->company, $this->appInstance);

        $this->assertEquals($chosen->getId(), $resolved?->getId());
        $this->assertNotEquals($this->flagDefault?->getId(), $resolved?->getId());
    }

    public function testIgnoresCustomFieldPointingOutsideCompanyAndGlobalScope(): void
    {
        $foreign = $this->createRegion(companiesId: $this->company->getId() + 999999);
        $this->company->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $foreign->getId());

        $resolved = Regions::getDefault($this->company, $this->appInstance);

        $this->assertEquals($this->flagDefault?->getId(), $resolved?->getId());
        $this->assertNotEquals($foreign->getId(), $resolved?->getId());
    }

    public function testIgnoresCustomFieldPointingAtADeletedRegion(): void
    {
        $deleted = $this->createRegion(companiesId: $this->company->getId());
        $deleted->is_deleted = 1;
        $deleted->saveOrFail();

        $this->company->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $deleted->getId());

        $resolved = Regions::getDefault($this->company, $this->appInstance);

        $this->assertEquals($this->flagDefault?->getId(), $resolved?->getId());
    }

    public function testIgnoresCustomFieldPointingAtAnotherApp(): void
    {
        $otherApp = $this->createRegion(companiesId: $this->company->getId());
        $otherApp->apps_id = $this->appInstance->getId() + 999999;
        $otherApp->saveOrFail();

        $this->company->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $otherApp->getId());

        $resolved = Regions::getDefault($this->company, $this->appInstance);

        $this->assertEquals($this->flagDefault?->getId(), $resolved?->getId());
    }

    /**
     * Inventory\Regions inherits getDefault() instead of re-using DefaultTrait, so the trait has to
     * resolve through late static binding — otherwise callers type-hinted on the subclass (such as
     * RegionResolutionService::forCompany) get a TypeError on the flag fallback.
     */
    public function testInventoryRegionsReturnsItsOwnClassOnBothBranches(): void
    {
        if ($this->flagDefault === null) {
            $this->markTestSkipped('Tenant fixture has no is_default region to exercise the flag branch');
        }

        $this->assertInstanceOf(
            InventoryRegions::class,
            InventoryRegions::getDefault($this->company, $this->appInstance)
        );

        $chosen = $this->createRegion(companiesId: $this->company->getId());
        $this->company->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $chosen->getId());

        $resolved = InventoryRegions::getDefault($this->company, $this->appInstance);

        $this->assertInstanceOf(InventoryRegions::class, $resolved);
        $this->assertEquals($chosen->getId(), $resolved->getId());
    }

    private function createRegion(int $companiesId): Regions
    {
        return Regions::create([
            'apps_id' => $this->appInstance->getId(),
            'companies_id' => $companiesId,
            'users_id' => 0,
            'currency_id' => Currencies::getBaseCurrency()->getId(),
            'name' => 'Region ' . uniqid(),
            'is_default' => 0,
            'is_deleted' => 0,
        ]);
    }
}
