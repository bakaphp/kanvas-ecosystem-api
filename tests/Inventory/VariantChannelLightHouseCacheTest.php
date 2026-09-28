<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;
use Tests\TestCase;

class VariantChannelLightHouseCacheTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    /**
     * The channelVariants storefront query exposes a variant as the VariantChannel
     * GraphQL type, whose @cacheRedis fields cache under the "VariantChannel" parent
     * name — not the model's getGraphTypeName() = "Variant". Clearing the variant's
     * lighthouse cache must drop BOTH namespaces, otherwise the storefront serves
     * stale files/attributes/warehouses forever. Regression for Sentry KANVAS-ECOSYSTEM-3GW.
     *
     * @test
     */
    public function testClearLightHouseCacheAlsoClearsVariantChannelNamespace(): void
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
                name: 'VariantChannelCacheProduct-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $redis = Redis::connection('graph-cache');
        $variantKey = CacheKeyAndTagsGenerator::PREFIX . ':Variant:' . $variant->getId();
        $variantChannelKey = CacheKeyAndTagsGenerator::PREFIX . ':VariantChannel:' . $variant->getId();

        $redis->hSet($variantKey, 'files:first:25', 'cached');
        $redis->hSet($variantChannelKey, 'files:first:25', 'cached');

        $this->assertSame(1, $redis->exists($variantKey));
        $this->assertSame(1, $redis->exists($variantChannelKey));

        $variant->clearLightHouseCache(withKanvasConfiguration: false);

        $this->assertSame(0, $redis->exists($variantKey), 'Variant namespace should be cleared');
        $this->assertSame(0, $redis->exists($variantChannelKey), 'VariantChannel namespace should be cleared');
    }
}
