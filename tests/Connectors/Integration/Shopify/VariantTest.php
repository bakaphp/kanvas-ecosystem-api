<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Social\Channels\Models\Channel;
use Tests\TestCase;

final class VariantTest extends TestCase
{
    public function testCreateVariant()
    {
        $product = Products::first();

        $region = Regions::fromCompany($product->company)->first();
        $channel = Channels::fromCompany($product->company)->first();

        /*
                ShopifyConfigurationService::setup(new Shopify(
                    $product->company,
                    $product->app,
                    $region,
                )); */

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

        /*
                ShopifyConfigurationService::setup(new Shopify(
                    $product->company,
                    $product->app,
                    $region,
                )); */

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
}
