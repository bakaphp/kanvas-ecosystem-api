<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Inventory\Variants\Models\Variants;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class VariantTagsTest extends TestCase
{
    use InventoryCases;

    public function testCreateVariantWithTags(): void
    {
        $tagName = 'tag-' . fake()->unique()->uuid();
        $variant = $this->createVariantWithTags([['name' => $tagName]]);

        $this->assertSame(
            [$tagName],
            $this->queryVariantTagNames((int) $variant['id'])
        );
    }

    public function testUpdateVariantReplacesItsTags(): void
    {
        $variant = $this->createVariantWithTags([['name' => 'tag-' . fake()->unique()->uuid()]]);
        $newTagName = 'tag-' . fake()->unique()->uuid();

        $response = $this->graphQL('
            mutation($id: ID! $data: VariantsUpdateInput!) {
                updateVariant(id: $id, input: $data) {
                    id
                }
            }
        ', [
            'id' => $variant['id'],
            'data' => [
                'name' => $variant['name'],
                'sku' => $variant['sku'],
                'tags' => [['name' => $newTagName]],
            ],
        ]);

        $this->graphQLData($response, 'updateVariant');

        $this->assertSame(
            [$newTagName],
            $this->queryVariantTagNames((int) $variant['id'])
        );
    }

    public function testTagsAreSentToTheSearchIndex(): void
    {
        $tagName = 'tag-' . fake()->unique()->uuid();
        $variant = $this->createVariantWithTags([['name' => $tagName]]);

        /** @var Variants $variantModel */
        $variantModel = Variants::getById((int) $variant['id']);
        $searchable = $variantModel->toSearchableArray();

        $this->assertSame([$tagName], $searchable['tags']);
        $this->assertSame([$tagName], $variantModel->toSearchableArraySummary()['tags']);
    }

    public function testTypesenseSchemaDeclaresTagsAsFacetableStringArray(): void
    {
        $fields = collect(new Variants()->typesenseCollectionSchema()['fields'])->keyBy('name');

        $this->assertSame('string[]', $fields['tags']['type']);
        $this->assertTrue($fields['tags']['facet']);
        $this->assertTrue($fields['tags']['optional']);
    }

    private function createVariantWithTags(array $tags): array
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
                'tags' => $tags,
            ]
        );

        return $this->graphQLData($response, 'createVariant');
    }

    private function queryVariantTagNames(int $variantId): array
    {
        $response = $this->graphQL('
            query($where: QueryVariantsWhereWhereConditions) {
                variants(where: $where) {
                    data {
                        id
                        tags {
                            data {
                                name
                            }
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $variantId,
            ],
        ]);

        $variants = $this->graphQLData($response, 'variants');

        return array_column($variants['data'][0]['tags']['data'], 'name');
    }
}
