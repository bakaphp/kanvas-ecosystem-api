<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Tests\Connectors\Traits\HasShopifyConfiguration;
use Tests\TestCase;

final class ProductTest extends TestCase
{
    use HasShopifyConfiguration;

    public function testCreateProduct()
    {
        /**
         * @todo use create product factory or action instead of first
         */
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

        $shopifyResponse = $shopify->saveProduct($product, StatusEnum::ACTIVE);

        $this->assertEquals(
            $product->name,
            $shopifyResponse['title']
        );

        //This test will never work @kaioken, product created on shopify and our own database not synced.
        // $this->assertEquals(
        //     strtolower($product->slug),
        //     $shopifyResponse['handle']
        // );

        $this->assertEquals(
            $product->getShopifyId($warehouse->regions),
            $shopifyResponse['id']
        );

        $this->assertEquals(
            $product->variants->count(),
            count($shopifyResponse['variants'])
        );
    }

    public function testUpdateProduct()
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

        $product->name = fake()->name;
        $product->saveOrFail();

        $shopifyResponse = $shopify->saveProduct($product, StatusEnum::ACTIVE);

        $this->assertEquals(
            $product->name,
            $shopifyResponse['title']
        );

        $this->assertEquals(
            $product->getShopifyId($warehouse->regions),
            $shopifyResponse['id']
        );

        $this->assertEquals(
            $product->variants->count(),
            count($shopifyResponse['variants'])
        );
    }

    public function testDeleteProduct()
    {
        $product = Products::first();
        $variant = $product->variants()->first();
        $warehouse = $variant->warehouses()->first();
        $this->setupShopifyConfiguration($product, $warehouse);

        $shopify = new ShopifyInventoryService(
            $product->app,
            $product->company,
            $warehouse
        );

        $shopifyResponse = $shopify->unPublishProduct($product);

        $this->assertEquals(
            $product->getShopifyId($warehouse->regions),
            $shopifyResponse['id']
        );

        $this->assertEquals(
            StatusEnum::ARCHIVED->value,
            $shopifyResponse['status']
        );
    }
}
