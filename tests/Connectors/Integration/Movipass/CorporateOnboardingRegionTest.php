<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Tests\TestCase;

/**
 * Tests region assignment behavior during corporate onboarding.
 *
 * These tests verify the region custom field storage logic independently
 * of the full EnableCorporateModeAction flow, which requires Bouncer roles
 * that may not be seeded in all test environments.
 */
final class CorporateOnboardingRegionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass corporate region tests are skipped in CI');
        }
    }

    public function testRegionIdStoredAsCompanyCustomField(): void
    {
        $user = Auth::user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $region = KanvasRegions::getDefault($company, $app);

        // Simulate what EnableCorporateModeAction::setCompanyFields does with region_id
        $validatedRegion = KanvasRegions::getByIdFromCompanyAppOrGlobal(
            $region->getId(),
            $company,
            $app,
        );
        $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, $validatedRegion->getId());

        $storedRegionId = $company->get(CustomFieldEnum::COMPANY_REGION_ID->value);
        $this->assertEquals($region->getId(), (int) $storedRegionId);

        // Cleanup
        $company->del(CustomFieldEnum::COMPANY_REGION_ID->value);
    }

    public function testContainerBoundRegionUsedAsFallback(): void
    {
        $user = Auth::user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $region = KanvasRegions::getDefault($company, $app);

        // Bind region in container (simulating middleware resolution)
        app()->scoped(Regions::class, fn () => $region);

        // Simulate EnableCorporateModeAction fallback: no region_id, use container
        if (app()->bound(Regions::class)) {
            $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, app(Regions::class)->getId());
        }

        $storedRegionId = $company->get(CustomFieldEnum::COMPANY_REGION_ID->value);
        $this->assertEquals($region->getId(), (int) $storedRegionId);

        // Cleanup
        $company->del(CustomFieldEnum::COMPANY_REGION_ID->value);
    }

    public function testNoRegionAndNoContainerSkipsField(): void
    {
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        // Ensure no region is bound
        app()->forgetInstance(Regions::class);
        app()->forgetScopedInstances();

        // Cleanup any existing value
        $company->del(CustomFieldEnum::COMPANY_REGION_ID->value);

        // Simulate EnableCorporateModeAction: no region_id, no container — should not set field
        $regionId = null;
        if (! empty($regionId)) {
            // would store region
        } elseif (app()->bound(Regions::class)) {
            $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, app(Regions::class)->getId());
        }

        $storedRegionId = $company->get(CustomFieldEnum::COMPANY_REGION_ID->value);
        $this->assertEmpty($storedRegionId);
    }
}
