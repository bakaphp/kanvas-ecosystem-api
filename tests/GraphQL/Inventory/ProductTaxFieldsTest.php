<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class ProductTaxFieldsTest extends TestCase
{
    use InventoryCases;

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

    public function testTaxCategoriesReturnsDistinctCodesFromActiveRules(): void
    {
        $app = app(Apps::class);

        $this->createRule($app, ['general_tangible', 'digital_goods'], true);
        $this->createRule($app, ['digital_goods', 'food'], true);
        $this->createRule($app, ['inactive_only'], false);

        $categories = $this->graphQL('{ taxCategories }')->json('data.taxCategories');

        $this->assertContains('general_tangible', $categories);
        $this->assertContains('digital_goods', $categories);
        $this->assertContains('food', $categories);
        $this->assertNotContains('inactive_only', $categories);
        $this->assertSame($categories, array_values(array_unique($categories)));
    }

    private function createRule(Apps $app, array $categoryCodes, bool $isActive): TaxRoutingRule
    {
        $rule = new TaxRoutingRule();
        $rule->apps_id = $app->getId();
        $rule->name = fake()->words(3, true);
        $rule->condition_country = 'DO';
        $rule->provider = 'manual';
        $rule->category_codes = $categoryCodes;
        $rule->is_active = $isActive;
        $rule->save();

        return $rule;
    }
}
