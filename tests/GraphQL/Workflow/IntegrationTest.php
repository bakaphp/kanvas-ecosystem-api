<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Workflows\Activities\SyncProductWithShopifyWithIntegrationActivity;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasShopifyConfiguration;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use InventoryCases;
    use HasShopifyConfiguration;

    public function testIntegrationCompanySave(): void
    {
        $integrationCompany = $this->createShopifyIntegrationCompany();

        $this->assertArrayHasKey('id', $integrationCompany);
    }

    public function testRemoveIntegrationCompany(): void
    {
        $integrationCompany = $this->createShopifyIntegrationCompany();

        $this->graphQL('
        mutation($id: ID!) {
            removeIntegrationCompany(id: $id)
        }', ['id' => $integrationCompany['id']])->assertJson([
            'data' => ['removeIntegrationCompany' => true],
        ]);
    }

    public function testIntegrationsSearch(): void
    {
        $needle = IntegrationsEnum::SHOPIFY->value;

        $response = $this->graphQL('
            query($search: String) {
                integrations(search: $search) {
                    data {
                        id
                        name
                    }
                }
            }
        ', ['search' => $needle])->assertSuccessful();

        $data = $response->json()['data']['integrations']['data'];

        $this->assertNotEmpty($data, 'Searching integrations by name must return the matching catalog row');
        $this->assertContains(
            $needle,
            array_column($data, 'name'),
            'Search results must include the integration matched by name'
        );
    }

    public function testGetIntegrationsWorkflowHistory(): void
    {
        $this->createProduct();

        $response = $this->graphQL('
        query {
            workflowIntegrationsHistory {
                data {
                    id,
                    entity_namespace
                }
            }
        }');

        $this->assertNotEmpty($response->json()['data']['workflowIntegrationsHistory']['data'][0]);
    }

    /**
     * The catalog holds far more integrations than the query's page size and `integrations` has no
     * default ordering, so filtering by name is the only way to reach Shopify — CI seeds it last,
     * which puts it well past page one.
     */
    protected function fetchShopifyIntegration(): array
    {
        $response = $this->graphQL('
            query($where: QueryIntegrationsWhereWhereConditions) {
                integrations(where: $where) {
                    data {
                        id
                        name
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'NAME',
                'operator' => 'EQ',
                'value' => IntegrationsEnum::SHOPIFY->value,
            ],
        ])->assertSuccessful();

        $integration = collect($response->json()['data']['integrations']['data'])
            ->firstWhere('name', IntegrationsEnum::SHOPIFY->value);

        $this->assertNotNull($integration, 'Shopify integration must be present in integrations query');

        return $integration;
    }

    protected function createTestRegion(): array
    {
        $regionSlug = 'test-region-' . uniqid();

        $response = $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => [
                'name' => 'Test Region ' . $regionSlug,
                'slug' => $regionSlug,
                'short_slug' => $regionSlug,
                'is_default' => 1,
                'currency_id' => 1,
            ],
        ])->assertSuccessful();

        return $response->json()['data']['createRegion'];
    }

    protected function createShopifyIntegrationCompany(): array
    {
        $integration = $this->fetchShopifyIntegration();
        $region = $this->createTestRegion();
        $company = auth()->user()->getCurrentCompany();

        $data = [
            'integration' => [
                'id' => $integration['id'],
            ],
            'company_id' => $company->getId(),
            'region' => [
                'id' => $region['id'],
            ],
            'config' => [
                'client_id' => env('TEST_SHOPIFY_API_KEY'),
                'client_secret' => env('TEST_SHOPIFY_API_SECRET'),
                'shop_url' => env('TEST_SHOPIFY_SHOP_URL'),
            ],
        ];

        $response = $this->graphQL('
        mutation($data: IntegrationsCompaniesInput!) {
            integrationCompany(input: $data)
            {
                id
            }
        }', ['data' => $data])->assertSuccessful();

        return $response->json()['data']['integrationCompany'];
    }

    protected function createProduct()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $product = Products::factory()->withCompanyId($company->getId())->withUserId($user->getId())->create();

        $region = $this->createDefaultRegion(
            company: $product->company,
            app: $app,
            user: $product->user
        );

        $this->createDefaultStatus(
            company: $product->company,
            app: $app,
            user: $product->user
        );

        $this->createDefaultWarehouse(
            company: $product->company,
            app: $app,
            user: $product->user,
            region: $region
        );

        $variant = VariantService::createDefaultVariant($product, $product->user);
        $warehouse = $variant->warehouses()->first();
        $this->setupShopifyConfiguration($product, $warehouse);
        $this->setupShopifyIntegration($product, $warehouse->region);

        $exportActivity = new SyncProductWithShopifyWithIntegrationActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $exportActivity->execute(
            product: $product,
            app: $app,
            params: []
        );

        $this->assertArrayHasKey('shopify_response', $result);
        $this->assertArrayHasKey('company', $result);
        $this->assertArrayHasKey('product', $result);
    }
}
