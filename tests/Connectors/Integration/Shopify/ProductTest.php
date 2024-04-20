<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Tests\TestCase;

final class ProductTest extends TestCase
{
    public function testCreateProduct()
    {
        $product = Products::first();
        $region = Regions::fromCompany($product->company)->first();
        /*
                ShopifyConfigurationService::setup(new Shopify(
                    $product->company,
                    $product->app,
                    $region,
                )); */

        $shopify = new ShopifyInventoryService(
            $product->app,
            $product->company,
            $region
        );

        $shopifyResponse = $shopify->saveProduct($product, StatusEnum::ACTIVE);

        $this->assertEquals(
            $product->name,
            $shopifyResponse['title']
        );

        $this->assertEquals(
            strtolower($product->slug),
            $shopifyResponse['handle']
        );

        $this->assertEquals(
            $product->getShopifyId($region),
            $shopifyResponse['id']
        );
    }
}
