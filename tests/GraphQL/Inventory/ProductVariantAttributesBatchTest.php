<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Products\Actions\AddAttributeAction as AddProductAttributeAction;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Regression for Sentry KANVAS-ECOSYSTEM-52J: Variant.attributes (@method visibleAttributes)
 * fired one products_variants_attributes query per variant. The Product.variants builder now
 * eager-loads visibleAttributesRelation, so all variants' visible attributes load in a single
 * batched query — critical for a bulk products list (sitemap crawl) on a cold cache.
 */
class ProductVariantAttributesBatchTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    public function testVariantAttributesAreBatchedInProductsQuery(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'BatchAttrsProduct-' . uniqid(),
            ),
            $user
        )->execute();

        $attributeSlug = 'color-' . uniqid();
        $attribute = new CreateAttribute(
            new AttributesDto(
                company: $company,
                app: $app,
                user: $user,
                name: 'Color-' . uniqid(),
                slug: $attributeSlug,
                attributeType: null,
                isVisible: true,
            ),
            $user
        )->execute();

        // Several variants, each with a visible attribute — enough to surface an N+1.
        for ($i = 0; $i < 4; $i++) {
            $variant = new CreateVariantsAction(
                new VariantsDto(
                    product: $product,
                    name: 'BatchVariant-' . $i . '-' . uniqid(),
                    sku: fake()->unique()->uuid(),
                ),
                $user
            )->execute();

            new AddAttributeAction($variant, $attribute, 'value-' . $i)->execute();
        }

        DB::connection('inventory')->flushQueryLog();
        DB::connection('inventory')->enableQueryLog();

        $this->graphQL('
            query ($where: QueryProductsWhereWhereConditions) {
                products(where: $where) {
                    data {
                        id
                        variants {
                            id
                            attributes { name value }
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $product->getId(),
            ],
        ])->assertSuccessful();

        $queries = DB::connection('inventory')->getQueryLog();
        DB::connection('inventory')->disableQueryLog();

        $attributeQueries = array_filter(
            $queries,
            fn (array $q): bool => str_contains($q['query'], 'products_variants_attributes')
                && str_contains($q['query'], 'is_visible')
        );

        $this->assertLessThanOrEqual(
            1,
            count($attributeQueries),
            'Variant visible-attributes must load in a single batched query, not one per variant. Got: ' . count($attributeQueries)
        );
    }

    public function testProductAttributesAreBatchedInProductsQuery(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $attribute = new CreateAttribute(
            new AttributesDto(
                company: $company,
                app: $app,
                user: $user,
                name: 'Material-' . uniqid(),
                slug: 'material-' . uniqid(),
                attributeType: null,
                isVisible: true,
            ),
            $user
        )->execute();

        // Several products, each with a visible attribute — enough to surface an N+1.
        $productIds = [];
        for ($i = 0; $i < 4; $i++) {
            $product = new CreateProductAction(
                new ProductDto(
                    app: $app,
                    company: $company,
                    user: $user,
                    name: 'BatchAttrsProduct-' . $i . '-' . uniqid(),
                ),
                $user
            )->execute();

            new AddProductAttributeAction($product, $attribute, 'value-' . $i)->execute();
            $productIds[] = $product->getId();
        }

        DB::connection('inventory')->flushQueryLog();
        DB::connection('inventory')->enableQueryLog();

        $this->graphQL('
            query ($where: QueryProductsWhereWhereConditions) {
                products(where: $where) {
                    data {
                        id
                        attributes { name value }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'IN',
                'value' => $productIds,
            ],
        ])->assertSuccessful();

        $queries = DB::connection('inventory')->getQueryLog();
        DB::connection('inventory')->disableQueryLog();

        $attributeQueries = array_filter(
            $queries,
            fn (array $q): bool => str_contains($q['query'], 'products_attributes')
                && ! str_contains($q['query'], 'products_variants_attributes')
                && str_contains($q['query'], 'is_visible')
        );

        $this->assertLessThanOrEqual(
            1,
            count($attributeQueries),
            'Product visible-attributes must load in a single batched query, not one per product. Got: ' . count($attributeQueries)
        );
    }
}
