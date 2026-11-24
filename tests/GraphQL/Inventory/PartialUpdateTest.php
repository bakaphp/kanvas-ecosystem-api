<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Testing\TestResponse;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class PartialUpdateTest extends TestCase
{
    use InventoryCases;

    public function testUpdateProductWithoutOptionalFieldsKeepsNameAndType(): void
    {
        $product = $this->createProductWithType();

        $response = $this->updateProduct($product['id'], ['description' => 'Only the description changed']);

        $updated = $this->graphQLData($response, 'updateProduct');
        $this->assertSame($product['name'], $updated['name']);
        $this->assertSame('Only the description changed', $updated['description']);
        $this->assertSame($product['productsTypes']['id'], $updated['productsTypes']['id'] ?? null);
    }

    public function testUpdateVariantWithoutSlugOrPublishKeepsBoth(): void
    {
        $variant = $this->createUnpublishedVariant();

        $response = $this->graphQL('
            mutation($id: ID!, $data: VariantsUpdateInput!) {
                updateVariant(id: $id, input: $data) {
                    id
                    slug
                    is_published
                }
            }', [
            'id' => $variant['id'],
            'data' => ['name' => 'Renamed variant ' . uniqid(), 'sku' => $variant['sku']],
        ]);

        $updated = $this->graphQLData($response, 'updateVariant');
        $this->assertSame($variant['slug'], $updated['slug']);
        $this->assertFalse($updated['is_published']);
    }

    public function testCreateVariantWithoutPublishFlagIsPublished(): void
    {
        $variant = $this->createVariantWith([]);

        $this->assertTrue($variant['is_published']);
    }

    private function createProductWithType(): array
    {
        $this->setupInventory();

        $productType = $this->graphQLData($this->graphQL('
            mutation($data: ProductTypeInput!) {
                createProductType(input: $data) { id }
            }', ['data' => ['name' => 'Type ' . uniqid(), 'weight' => 1]]), 'createProductType');

        $response = $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data) {
                    id
                    name
                    productsTypes { id }
                }
            }', ['data' => [
            'name' => 'Partial ' . uniqid(),
            'description' => 'Original description',
            'sku' => uniqid('sku-'),
            'products_types_id' => $productType['id'],
        ]]);

        return $this->graphQLData($response, 'createProduct');
    }

    private function updateProduct(string $id, array $data): TestResponse
    {
        return $this->graphQL('
            mutation($id: ID!, $data: ProductInputUpdate!) {
                updateProduct(id: $id, input: $data) {
                    id
                    name
                    description
                    productsTypes { id }
                }
            }', ['id' => $id, 'data' => $data]);
    }

    private function createUnpublishedVariant(): array
    {
        $variant = $this->createVariantWith([
            'slug' => 'custom-slug-' . uniqid(),
            'is_published' => false,
        ]);
        $this->assertFalse($variant['is_published']);

        return $variant;
    }

    private function createVariantWith(array $data): array
    {
        $productId = $this->graphQLData($this->createProduct(), 'createProduct')['id'];

        $response = $this->graphQL('
            mutation($data: VariantsInput!) {
                createVariant(input: $data) {
                    id
                    sku
                    slug
                    is_published
                }
            }', ['data' => [
            'name' => 'Variant ' . uniqid(),
            'products_id' => $productId,
            'sku' => uniqid('sku-'),
            'warehouses' => [['id' => $this->createWarehouseId()]],
            ...$data,
        ]]);

        return $this->graphQLData($response, 'createVariant');
    }

    private function createWarehouseId(): string
    {
        $region = $this->graphQLData($this->createRegion(), 'createRegion');

        return $this->graphQLData($this->createWarehouses($region['id']), 'createWarehouse')['id'];
    }
}
