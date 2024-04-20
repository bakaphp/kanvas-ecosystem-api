<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Tests\TestCase;

final class VariantTest extends TestCase
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
}
