<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Regression for Sentry KANVAS-ECOSYSTEM-4K3: the Variant.channel (VariantPricingInfo) resolver
 * fired a per-variant cluster — companies, channels (getDefault), products_variants_channels and
 * products_variants_warehouses — one set per variant. The list builder now batch-loads the
 * channel rows and the resolver picks the default channel in memory, so a bulk products list
 * (sitemap crawl) resolves pricing without the N+1.
 */
class ProductVariantChannelBatchTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    public function testVariantChannelPricingIsBatchedInProductsQuery(): void
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
                name: 'ChannelBatchProduct-' . uniqid(),
            ),
            $user
        )->execute();

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->firstOrFail();
        $channel = Channels::fromApp($app)->fromCompany($company)->where('is_default', 1)->firstOrFail();

        $expectedPrice = 42.50;

        // The product's default variant plus a few more, each priced on the default channel.
        $variantIds = [$product->variants()->where('is_deleted', 0)->firstOrFail()->getId()];
        for ($i = 0; $i < 3; $i++) {
            $variantIds[] = new CreateVariantsAction(
                new VariantsDto(
                    product: $product,
                    name: 'ChannelBatchVariant-' . $i . '-' . uniqid(),
                    sku: fake()->unique()->uuid(),
                ),
                $user
            )->execute()->getId();
        }

        foreach ($variantIds as $variantId) {
            $variantWarehouse = VariantsWarehouses::updateOrCreate(
                [
                    'products_variants_id' => $variantId,
                    'warehouses_id' => $warehouse->getId(),
                ],
                [
                    'quantity' => 5,
                    'price' => $expectedPrice,
                    'sku' => 'ChannelBatchSku-' . uniqid(),
                    'position' => 1,
                    'is_default' => 1,
                ]
            );

            new VariantsChannels([
                'product_variants_warehouse_id' => $variantWarehouse->getId(),
                'channels_id' => $channel->getId(),
                'products_variants_id' => $variantId,
                'warehouses_id' => $warehouse->getId(),
                'price' => $expectedPrice,
                'discounted_price' => $expectedPrice - 2,
                'is_published' => true,
            ])->save();
        }

        DB::connection('inventory')->flushQueryLog();
        DB::connection('inventory')->enableQueryLog();

        $response = $this->graphQL('
            query ($where: QueryProductsWhereWhereConditions) {
                products(where: $where) {
                    data {
                        id
                        variants {
                            id
                            channel { price discounted_price }
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

        // Pricing must still be correct for every variant.
        $variants = $response->json('data.products.data.0.variants');
        $this->assertCount(count($variantIds), $variants);
        foreach ($variants as $variant) {
            $this->assertEquals($expectedPrice, $variant['channel']['price']);
        }

        $channelInfoQueries = array_filter(
            $queries,
            fn (array $q): bool => str_contains($q['query'], 'products_variants_channels')
        );

        $this->assertLessThanOrEqual(
            1,
            count($channelInfoQueries),
            'Variant channel pricing must batch-load, not fire products_variants_channels per variant. Got: ' . count($channelInfoQueries)
        );
    }

    public function testVariantChannelPricingResolvesViaStandaloneVariantsQuery(): void
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
                name: 'StandaloneChannelProduct-' . uniqid(),
            ),
            $user
        )->execute();

        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->firstOrFail();
        $channel = Channels::fromApp($app)->fromCompany($company)->where('is_default', 1)->firstOrFail();

        $expectedPrice = 77.25;

        $variantWarehouse = VariantsWarehouses::updateOrCreate(
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ],
            [
                'quantity' => 3,
                'price' => $expectedPrice,
                'sku' => 'StandaloneSku-' . uniqid(),
                'position' => 1,
                'is_default' => 1,
            ]
        );

        new VariantsChannels([
            'product_variants_warehouse_id' => $variantWarehouse->getId(),
            'channels_id' => $channel->getId(),
            'products_variants_id' => $variant->getId(),
            'warehouses_id' => $warehouse->getId(),
            'price' => $expectedPrice,
            'discounted_price' => $expectedPrice - 5,
            'is_published' => true,
        ])->save();

        // The top-level variants query does NOT run the products builder, so this exercises
        // ChannelInfoType::price's loadMissing fallback rather than the pre-loaded path.
        $this->graphQL('
            query ($where: QueryVariantsWhereWhereConditions) {
                variants(where: $where) {
                    data {
                        id
                        channel { price discounted_price quantity }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $variant->getId(),
            ],
        ])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'variants' => [
                        'data' => [
                            [
                                'id' => (string) $variant->getId(),
                                'channel' => [
                                    'price' => $expectedPrice,
                                    'discounted_price' => $expectedPrice - 5,
                                    'quantity' => 3,
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
