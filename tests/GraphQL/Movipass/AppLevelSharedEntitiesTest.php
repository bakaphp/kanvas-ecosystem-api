<?php

declare(strict_types=1);

namespace Tests\GraphQL\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class AppLevelSharedEntitiesTest extends TestCase
{
    use DatabaseTransactions;

    public function setUp(): void
    {
        parent::setUp();
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Enable cross-company variants (multi-company orders) for the test app
        $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 1);

        // Ensure test user has Owner role in all relevant scopes
        foreach ([
            RolesEnums::getScope($app),
            RolesEnums::getScope($app, $company),
            RolesEnums::getScope($app, global: true),
        ] as $scope) {
            Bouncer::scope()->to($scope);
            Bouncer::assign(RolesEnums::OWNER->value)->to($user);
        }
        Bouncer::refreshFor($user);
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
        $response = $this->graphQL('
            mutation($input: RegionInput!) {
                createRegion(input: $input) {
                    id
                    name
                    companies_id
                }
            }
        ', [
            'input' => [
                'name' => 'Global Region ' . fake()->word(),
                'short_slug' => fake()->lexify('???'),
                'currency_id' => 1,
                'is_default' => 0,
                'companies_id' => 0,
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $id = $response->json('data.createRegion.id');
        $companiesId = (int) $response->json('data.createRegion.companies_id');

        $this->assertEquals(0, $companiesId, 'Region should be global (companies_id = 0)');
    }

    public function testGlobalRegionIsVisibleToCurrentCompany(): void
    {
        $name = 'Visible Global ' . fake()->word();

        $createResponse = $this->graphQL('
            mutation($input: RegionInput!) {
                createRegion(input: $input) { id }
            }
        ', [
            'input' => [
                'name' => $name,
                'short_slug' => fake()->lexify('???'),
                'currency_id' => 1,
                'is_default' => 0,
                'companies_id' => 0,
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $id = $createResponse->json('data.createRegion.id');

        $this->graphQL('
            query {
                regions(where: { column: ID, value: ' . $id . ' }) {
                    data { id name companies_id }
                }
            }
        ')->assertSuccessful()
         ->assertJsonFragment(['id' => (string) $id]);
    }

    public function testAdminCreatesGlobalProductType(): void
    {
        $response = $this->graphQL('
            mutation($input: ProductTypeInput!) {
                createProductType(input: $input) {
                    id
                    name
                    companies_id
                }
            }
        ', [
            'input' => [
                'name' => 'Global Type ' . fake()->word(),
                'weight' => 0,
                'companies_id' => 0,
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $id = $response->json('data.createProductType.id');
        $companiesId = (int) $response->json('data.createProductType.companies_id');

        $this->assertEquals(0, $companiesId, 'Product type should be global (companies_id = 0)');
    }

    public function testGlobalProductTypeVisibleInListing(): void
    {
        $name = 'Listed Global ' . fake()->word();

        $createResponse = $this->graphQL('
            mutation($input: ProductTypeInput!) {
                createProductType(input: $input) { id }
            }
        ', [
            'input' => [
                'name' => $name,
                'weight' => 0,
                'companies_id' => 0,
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $id = $createResponse->json('data.createProductType.id');

        $this->graphQL('
            query {
                productTypes(where: { column: ID, value: ' . $id . ' }) {
                    data { id name companies_id }
                }
            }
        ')->assertSuccessful()
         ->assertJsonFragment(['id' => (string) $id]);
    }
}
