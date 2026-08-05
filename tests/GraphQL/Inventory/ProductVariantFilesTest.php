<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * End-to-end coverage that the @paginate(resolver: getPaginatedFileByGraphType) swap
 * serializes files correctly through real GraphQL requests — for both the products list
 * (Product.files + nested Variant.files, Sentry KANVAS-ECOSYSTEM-5N4) and the storefront
 * channelVariants query (VariantChannel.files, Sentry KANVAS-ECOSYSTEM-3GW).
 */
class ProductVariantFilesTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    public function testProductAndVariantFilesReturnedViaGraphQL(): void
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
                name: 'GqlFilesProduct-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $productFileUrl = 'https://example.com/product-' . uniqid() . '.png';
        $variantFileUrl = 'https://example.com/variant-' . uniqid() . '.png';
        $product->addFileFromUrl($productFileUrl, 'product.png');
        $variant->addFileFromUrl($variantFileUrl, 'variant.png');

        $this->graphQL('
            query ($where: QueryProductsWhereWhereConditions) {
                products(where: $where) {
                    data {
                        id
                        files { data { url } }
                        variants {
                            id
                            files { data { url } }
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
        ])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'products' => [
                        'data' => [
                            [
                                'id' => (string) $product->getId(),
                                'files' => ['data' => [['url' => $productFileUrl]]],
                                'variants' => [
                                    ['files' => ['data' => [['url' => $variantFileUrl]]]],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function testChannelVariantFilesReturnedViaGraphQL(): void
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
                name: 'GqlChannelFilesProduct-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->firstOrFail();
        $channel = Channels::fromApp($app)->fromCompany($company)->firstOrFail();

        $variantWarehouse = VariantsWarehouses::updateOrCreate(
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ],
            [
                'quantity' => 1,
                'price' => 10.00,
                'sku' => $variant->sku ?? 'GqlChannelSku-' . uniqid(),
                'position' => 1,
                'is_default' => 1,
            ]
        );

        new VariantsChannels([
            'product_variants_warehouse_id' => $variantWarehouse->getId(),
            'channels_id' => $channel->getId(),
            'products_variants_id' => $variant->getId(),
            'warehouses_id' => $warehouse->getId(),
            'price' => 10.00,
            'discounted_price' => 9.00,
            'is_published' => true,
        ])->save();

        $channelFileUrl = 'https://example.com/channel-variant-' . uniqid() . '.png';
        $variant->addFileFromUrl($channelFileUrl, 'channel-variant.png');

        $this->graphQL('
            query ($id: String!) {
                channelVariants(id: $id) {
                    data {
                        id
                        files { data { url } }
                    }
                }
            }
        ', ['id' => $channel->uuid])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'channelVariants' => [
                        'data' => [
                            [
                                'id' => (string) $variant->getId(),
                                'files' => ['data' => [['url' => $channelFileUrl]]],
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
