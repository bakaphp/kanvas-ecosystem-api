<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Connectors\Shopify\DataTransferObject\Shopify;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Tests\Connectors\Traits\HasShopifyConfiguration;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Tests\TestCase;

final class VariantTest extends TestCase
{
    use HasShopifyConfiguration;

    public function testCreateVariant()
    {
        $product = Products::first();

        $region = Regions::fromCompany($product->company)->first();
        $channel = Channels::fromCompany($product->company)->first();
        $this->setupShopifyConfiguration($product, $region);

        $shopify = new ShopifyInventoryService(
            $product->app,
            $product->company,
            $region,
            $channel
        );

        $shopifyProduct = $shopify->saveProduct($product, StatusEnum::ACTIVE);

        foreach ($product->variants as $variant) {
            $shopifyVariantResponse = $shopify->saveVariant($variant);

            $this->assertEquals(
                $variant->sku,
                $shopifyVariantResponse['sku']
            );

            $this->assertEquals(
                $variant->getShopifyId($region),
                $shopifyVariantResponse['id']
            );
        }
    }

    public function testSetStock()
    {
        $product = Products::first();

        $region = Regions::fromCompany($product->company)->first();
        $channel = Channels::fromCompany($product->company)->first();
        $this->setupShopifyConfiguration($product, $region);

        $shopify = new ShopifyInventoryService(
            $product->app,
            $product->company,
            $region,
            $channel
        );

        $shopifyProduct = $shopify->saveProduct($product, StatusEnum::ACTIVE);

        foreach ($product->variants as $variant) {
            $shopify->saveVariant($variant);
            $shopifyVariantResponse = $shopify->setStock($variant);

            $channelInfo = $variant->variantChannels()->where('channels_id', $channel->getId())->first();
            $warehouseInfo = $channelInfo?->productVariantWarehouse()->first();

            $this->assertEquals(
                $warehouseInfo?->quantity ?? 0,
                $shopifyVariantResponse
            );
        }
    }

    public function testSetImage()
    {
        $product = Products::first();

        $region = Regions::fromCompany($product->company)->first();
        $channel = Channels::fromCompany($product->company)->first();
        $this->setupShopifyConfiguration($product, $region);

        $shopify = new ShopifyInventoryService(
            $product->app,
            $product->company,
            $region,
            $channel
        );

        $shopifyProduct = $shopify->saveProduct($product, StatusEnum::ACTIVE);

        foreach ($product->variants as $variant) {
            $shopify->saveVariant($variant);
            $shopifyVariantResponse = $shopify->addImages($variant, fake()->imageUrl(640, 480, 'animals', true));

            $this->assertEquals(
                $variant->image,
                $shopifyVariantResponse
            );
        }
    }
}
