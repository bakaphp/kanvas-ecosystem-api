<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
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
 * End-to-end regression for Sentry KANVAS-ECOSYSTEM-3GW: VariantChannel.files is @cacheRedis,
 * which caches under the "VariantChannel" parentType name — a namespace the base cache trait
 * (keyed on getGraphTypeName() = "Variant") never cleared. A file attached after the storefront
 * warmed the cache stayed invisible until Redis expiry. Variants::clearLightHouseCache() now
 * also clears the VariantChannel namespace, so the new file must appear on the next query.
 */
class VariantChannelFilesCacheInvalidationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    public function testAttachedFileIsVisibleAfterCacheWasWarmed(): void
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
                name: 'CacheInvalidationProduct-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->firstOrFail();

        // Dedicated channel so channelVariants returns only this test's variant — the shared
        // default channel is populated with other published variants across the seeded company.
        $channel = new CreateChannel(
            new ChannelsDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'CacheInvalidationChannel-' . uniqid(),
            ),
            $user
        )->execute();

        $variantWarehouse = VariantsWarehouses::updateOrCreate(
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ],
            [
                'quantity' => 1,
                'price' => 10.00,
                'sku' => $variant->sku ?? 'CacheInvalidationSku-' . uniqid(),
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

        $firstFileUrl = 'https://example.com/first-' . uniqid() . '.png';
        $variant->addFileFromUrl($firstFileUrl, 'first.png');

        // First query warms the @cacheRedis cache under the VariantChannel namespace.
        $this->queryChannelVariantFiles($channel->uuid, $variant->getId(), [$firstFileUrl]);

        // Attaching a file invalidates the cache; without the VariantChannel-namespace clear
        // this second file would stay hidden behind the stale cached payload.
        $secondFileUrl = 'https://example.com/second-' . uniqid() . '.png';
        $variant->addFileFromUrl($secondFileUrl, 'second.png');

        $this->queryChannelVariantFiles($channel->uuid, $variant->getId(), [$firstFileUrl, $secondFileUrl]);
    }

    /**
     * @param array<int, string> $expectedUrls
     */
    private function queryChannelVariantFiles(string $channelUuid, int $variantId, array $expectedUrls): void
    {
        $response = $this->graphQL('
            query ($id: String!) {
                channelVariants(id: $id) {
                    data {
                        id
                        files { data { url } }
                    }
                }
            }
        ', ['id' => $channelUuid])->assertSuccessful();

        $rows = collect($response->json('data.channelVariants.data'))
            ->firstWhere('id', (string) $variantId);

        $this->assertNotNull($rows, 'Variant not found in channelVariants response');

        $returnedUrls = collect($rows['files']['data'])->pluck('url')->all();

        sort($expectedUrls);
        sort($returnedUrls);

        $this->assertSame(
            $expectedUrls,
            $returnedUrls,
            'channelVariants files did not reflect the current set of attached files (stale cache)'
        );
    }
}
