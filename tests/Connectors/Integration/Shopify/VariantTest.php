<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyImageService;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Connectors\Shopify\Services\ShopifyVariantMetafieldService;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Variants\Services\VariantService;
use Tests\Connectors\Traits\HasShopifyConfiguration;
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

    public function testSetMetafield()
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
        $testAttribute = new CreateAttribute(
            new Attributes(
                company: $product->company,
                app: $product->app,
                user: $product->user,
                name: 'test',
                slug: 'test',
                attributeType: null,
                isVisible: true,
                isSearchable: true,
                isFiltrable: true
            ),
            $product->user
        );
        $attribute = $testAttribute->execute();

        foreach ($product->variants as $variant) {
            $shopifyVariantResponse = $shopify->saveVariant($variant);
            $this->assertEquals(
                $variant->sku,
                $shopifyVariantResponse['sku']
            );

            (new AddAttributeAction($variant, $attribute, 'test'))->execute();

            $shopifyMetafieldService = new ShopifyVariantMetafieldService(
                $product->app,
                $product->company,
                $warehouse->region,
                $variant
            );

            $totalSet = $shopifyMetafieldService->setMetaField();
            $totalGet = count($shopifyMetafieldService->getMetaField());

            $this->assertEquals(
                $totalSet,
                $totalGet
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

        $shopifyImageService = new ShopifyImageService(
            $product->app,
            $product->company,
            $warehouse->region
        );

        $shopifyProduct = $shopify->saveProduct($product, StatusEnum::ACTIVE);
        $url = 'https://www.google.com/images/branding/googlelogo/1x/googlelogo_color_272x92dp.png';

        foreach ($product->variants as $variant) {
            $this->assertTrue($shopifyImageService->addVariantImage($variant, $url));
            //$shopifyVariantResponse = $shopify->addImages($variant, $url);
        }
    }

    public function testDeleteVariant()
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
        $variant = $product->variants->first();

        $shopifyProductVariantId = $variant->getShopifyId($warehouse->regions);

        if ($shopifyProductVariantId === null) {
            $shopify->saveVariant($variant);
        }

        $this->assertEmpty($shopify->deleteVariant($variant));
    }
}
