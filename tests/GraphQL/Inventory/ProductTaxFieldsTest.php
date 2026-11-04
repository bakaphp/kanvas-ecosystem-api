<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Taxes\Actions\CreateTaxCategoryAction;
use Kanvas\Souk\Taxes\Models\TaxCategory;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class ProductTaxFieldsTest extends TestCase
{
    use InventoryCases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTaxCategories('general_tangible', 'digital_goods');
    }

    public function testCreateAndUpdateProductPersistTaxColumns(): void
    {
        $product = $this->graphQLData($this->createProduct([
            'name' => fake()->name,
            'sku' => fake()->unique()->uuid(),
            'tax_category' => 'general_tangible',
            'tax_code_override' => 'txcd_99999999',
        ]), 'createProduct');

        $response = $this->graphQL('
            mutation($id: ID!, $data: ProductInputUpdate) {
                updateProduct(id: $id, input: $data) {
                    id
                    tax_category
                    tax_code_override
                }
            }', ['id' => $product['id'], 'data' => [
            'name' => fake()->name,
            'tax_category' => 'digital_goods',
        ]]);

        $updated = $this->graphQLData($response, 'updateProduct');

        $this->assertSame('digital_goods', $updated['tax_category']);
        $this->assertSame('txcd_99999999', $updated['tax_code_override']);
    }

    public function testCreateAndUpdateVariantPersistTaxColumns(): void
    {
        $region = $this->graphQLData($this->createRegion(), 'createRegion');
        $warehouse = $this->graphQLData($this->createWarehouses($region['id']), 'createWarehouse');
        $product = $this->graphQLData($this->createProduct(), 'createProduct');

        $variant = $this->graphQLData($this->createVariant(
            productId: $product['id'],
            warehouseData: ['id' => $warehouse['id']],
            data: [
                'name' => fake()->name,
                'products_id' => $product['id'],
                'sku' => fake()->unique()->uuid(),
                'warehouses' => [['id' => $warehouse['id']]],
                'tax_category' => 'general_tangible',
            ]
        ), 'createVariant');

        $response = $this->graphQL('
            mutation($id: ID!, $data: VariantsUpdateInput!) {
                updateVariant(id: $id, input: $data) {
                    id
                    is_taxable
                    tax_category
                    tax_code_override
                }
            }', ['id' => $variant['id'], 'data' => [
            'name' => fake()->name,
            'sku' => $variant['sku'],
            'is_taxable' => false,
            'tax_code_override' => 'txcd_10000000',
        ]]);

        $updated = $this->graphQLData($response, 'updateVariant');

        $this->assertFalse($updated['is_taxable']);
        $this->assertSame('general_tangible', $updated['tax_category']);
        $this->assertSame('txcd_10000000', $updated['tax_code_override']);
    }

    public function testCreateVariantDefaultsToTaxable(): void
    {
        $region = $this->graphQLData($this->createRegion(), 'createRegion');
        $warehouse = $this->graphQLData($this->createWarehouses($region['id']), 'createWarehouse');
        $product = $this->graphQLData($this->createProduct(), 'createProduct');
        $variant = $this->graphQLData($this->createVariant(
            productId: $product['id'],
            warehouseData: ['id' => $warehouse['id']]
        ), 'createVariant');

        $response = $this->graphQL('
            query($id: Mixed!) {
                variants(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        is_taxable
                        tax_category
                    }
                }
            }', ['id' => $variant['id']]);

        $response->assertJsonPath('data.variants.data.0.is_taxable', true);
        $response->assertJsonPath('data.variants.data.0.tax_category', null);
    }

    public function testProductRejectsATaxCategoryOutsideTheCatalog(): void
    {
        $response = $this->createProduct([
            'name' => fake()->name,
            'sku' => fake()->unique()->uuid(),
            'tax_category' => 'not_in_catalog_' . uniqid(),
        ]);

        $this->assertStringContainsString('Unknown tax category', $response->json('errors.0.message'));
    }

    public function testVariantRejectsATaxCategoryOutsideTheCatalog(): void
    {
        $region = $this->graphQLData($this->createRegion(), 'createRegion');
        $warehouse = $this->graphQLData($this->createWarehouses($region['id']), 'createWarehouse');
        $product = $this->graphQLData($this->createProduct(), 'createProduct');

        $response = $this->createVariant(
            productId: $product['id'],
            warehouseData: ['id' => $warehouse['id']],
            data: [
                'name' => fake()->name,
                'products_id' => $product['id'],
                'sku' => fake()->unique()->uuid(),
                'warehouses' => [['id' => $warehouse['id']]],
                'tax_category' => 'not_in_catalog_' . uniqid(),
            ]
        );

        $this->assertStringContainsString('Unknown tax category', $response->json('errors.0.message'));
    }

    public function testNonTaxableNeedsNoCatalogRow(): void
    {
        $product = $this->graphQLData($this->createProduct([
            'name' => fake()->name,
            'sku' => fake()->unique()->uuid(),
            'tax_category' => 'non_taxable',
        ]), 'createProduct');

        $this->assertSame('non_taxable', Products::findOrFail($product['id'])->tax_category);
    }

    private function ensureTaxCategories(string ...$codes): void
    {
        $app = app(Apps::class);

        foreach ($codes as $code) {
            if (TaxCategory::findByCode($app, $code) === null) {
                new CreateTaxCategoryAction($app, ['code' => $code, 'name' => $code])->execute();
            }
        }
    }
}
