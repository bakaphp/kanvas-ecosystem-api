<?php

declare(strict_types=1);

namespace Tests\GraphQL\Movipass;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Products\Models\ProductsTypes;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Tests\TestCase;

class AppLevelSharedEntitiesTest extends TestCase
{
    use DatabaseTransactions;

    public function setUp(): void
    {
        parent::setUp();
        app(Apps::class)->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 1);
    }

    private function getAppKeyHeader(): array
    {
        $app = app(Apps::class);

        return [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ];
    }

    public function testAdminCreatesGlobalRegion(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $regionDto = Region::viaRequest([
            'name' => 'Global Region ' . fake()->word(),
            'short_slug' => fake()->lexify('???'),
            'currency_id' => 1,
            'is_default' => 0,
        ]);

        $region = new CreateRegionAction($regionDto, $user)->execute();

        // Simulate app-level entity by setting companies_id = 0
        $region->companies_id = 0;
        $region->saveOrFail();

        $this->assertEquals(0, $region->fresh()->companies_id, 'Region should be global (companies_id = 0)');
    }

    public function testGlobalRegionIsVisibleToCurrentCompany(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $regionDto = Region::viaRequest([
            'name' => 'Visible Global ' . fake()->word(),
            'short_slug' => fake()->lexify('???'),
            'currency_id' => 1,
            'is_default' => 0,
        ]);

        $region = new CreateRegionAction($regionDto, $user)->execute();
        $region->companies_id = 0;
        $region->saveOrFail();

        $this->graphQL('
            query {
                regions(where: { column: ID, value: ' . $region->getId() . ' }) {
                    data { id name companies_id }
                }
            }
        ')->assertSuccessful()
         ->assertJsonFragment(['id' => (string) $region->getId()]);
    }

    public function testAdminCreatesGlobalProductType(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $productType = new ProductsTypes();
        $productType->uuid = Str::uuid()->toString();
        $productType->apps_id = $app->getId();
        $productType->companies_id = 0;
        $productType->users_id = $user->getId();
        $productType->name = 'Global Type ' . fake()->word();
        $productType->slug = Str::slug('global-type-' . fake()->word() . '-' . uniqid());
        $productType->weight = 0;
        $productType->saveOrFail();

        $this->assertEquals(0, $productType->fresh()->companies_id, 'Product type should be global (companies_id = 0)');
    }

    public function testGlobalProductTypeVisibleInListing(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $productType = new ProductsTypes();
        $productType->uuid = Str::uuid()->toString();
        $productType->apps_id = $app->getId();
        $productType->companies_id = 0;
        $productType->users_id = $user->getId();
        $productType->name = 'Listed Global ' . fake()->word();
        $productType->slug = Str::slug('listed-global-' . fake()->word() . '-' . uniqid());
        $productType->weight = 0;
        $productType->saveOrFail();

        $this->graphQL('
            query {
                productTypes(where: { column: ID, value: ' . $productType->getId() . ' }) {
                    data { id name companies_id }
                }
            }
        ')->assertSuccessful()
         ->assertJsonFragment(['id' => (string) $productType->getId()]);
    }
}
