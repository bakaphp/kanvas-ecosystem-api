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

        $channel = Channels::fromCompany($product->company)->first();
        $variant = $product->variants()->first();
        $warehouse = $variant->warehouses()->first();
        $this->setupShopifyConfiguration($product, $warehouse);

        $shopify = new ShopifyInventoryService(
            $product->app,
            $product->company,
            $warehouse
        );

        $shopify->saveProduct($product, StatusEnum::ACTIVE);

        foreach ($product->variants as $variant) {
            $shopifyVariantResponse = $shopify->saveVariant($variant);

            $this->assertEquals(
                $variant->sku,
                $shopifyVariantResponse['sku']
            );

            $this->assertEquals(
                $variant->getShopifyId($warehouse->regions),
                $shopifyVariantResponse['id']
            );
        }
    }

    // public function testSetStock()
    // {
    //     $product = Products::first();

    //     $channel = Channels::fromCompany($product->company)->first();
    //     $variant = $product->variants()->first();
    //     $warehouse = $variant->warehouses()->first();
    //     $this->setupShopifyConfiguration($product, $warehouse);

    //     $shopify = new ShopifyInventoryService(
    //         $product->app,
    //         $product->company,
    //         $warehouse
    //     );

    //     $shopifyProduct = $shopify->saveProduct($product, StatusEnum::ACTIVE);

    //     foreach ($product->variants as $variant) {
    //         $shopify->saveVariant($variant);

    //         $channelInfo = $variant->variantChannels()->where('channels_id', $channel->getId())->first();
    //         $shopifyVariantResponse = $shopify->setStock($variant, $channelInfo);
    //         $warehouseInfo = $channelInfo?->productVariantWarehouse()->first();

    //         $this->assertEquals(
    //             $warehouseInfo?->quantity ?? 0,
    //             $shopifyVariantResponse
    //         );
    //     }
    // }

    public function testSetImage()
    {
        $product = Products::first();

        $channel = Channels::fromCompany($product->company)->first();
        $variant = $product->variants()->first();
        $warehouse = $variant->warehouses()->first();
        $this->setupShopifyConfiguration($product, $warehouse);

        $shopify = new ShopifyInventoryService(
            $product->app,
            $product->company,
            $warehouse
        );


        $shopifyProduct = $shopify->saveProduct($product, StatusEnum::ACTIVE);
        $url = 'https://www.google.com/images/branding/googlelogo/1x/googlelogo_color_272x92dp.png';

        foreach ($product->variants as $variant) {
            $shopify->saveVariant($variant);
            $shopifyVariantResponse = $shopify->addImages($variant, $url);

            $this->assertEquals(
                $variant->image,
                $shopifyVariantResponse
            );
        }
    }
}
