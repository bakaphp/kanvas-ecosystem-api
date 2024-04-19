<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

final class VariantTest extends TestCase
{
    public function testCreateProduct()
    {
        $variant = Variants::first();

        $region = Regions::fromCompany($variant->company)->first();
        /*
                ShopifyConfigurationService::setup(new Shopify(
                    $product->company,
                    $product->app,
                    $region,
                )); */

        $shopify = new ShopifyInventoryService(
            $variant->app,
            $variant->company,
            $region
        );

        print_r($shopify->saveVariant($variant));
    }
}
