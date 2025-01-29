<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Connectors\Shopify\Enums\ConfigEnum;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
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

    public function testProductWith100PlusVariant()
    {
        $product = Products::first();
        $channel = Channels::fromCompany($product->company)->first();
        $warehouse = $product->variants()->first()->warehouses()->first();
        $this->setupShopifyConfiguration($product, $warehouse);

        $shopify = new ShopifyInventoryService(
            $product->app,
            $product->company,
            $warehouse
        );

        $product->app->set(ConfigEnum::VARIANT_LIMIT->value, 5);
        $variants = [];
        for ($i = 0; $i < 10; $i++) {
            $variants[] = [
                'name' => fake()->name,
                'apps_id' => $product->app->getId(),
                'companies_id' => $product->company->getId(),
                'users_id' => $product->user->getId(),
                'sku' => fake()->uuid,
                'description' => fake()->sentence,
                'short_description' => fake()->sentence,
                'html_description' => fake()->sentence,
                'status_id' => 1,
                'ean' => fake()->uuid,
                'barcode' => fake()->uuid,
                'serial_number' => fake()->uuid,
                'weight' => 1,
            ];
        }

        //$product->variants()->delete();
        $product->variants()->createMany($variants);

        $shopifyResponse = $shopify->saveProduct($product, StatusEnum::ACTIVE);

        $this->assertEquals(
            $product->name,
            $shopifyResponse[0]['title']
        );
        $this->assertEquals(
            $product->name . ' (Part 2)',
            $shopifyResponse[1]['title']
        );
        $this->assertEquals(
            $product->name . ' (Part 3)',
            $shopifyResponse[2]['title']
        );
    }
}
