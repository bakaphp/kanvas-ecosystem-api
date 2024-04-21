<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Kanvas\Connectors\Shopify\DataTransferObject\Shopify;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;

trait HasShopifyConfiguration
{
    public function setupShopifyConfiguration(Products $product, Regions $region): void
    {
        echo getenv('TEST_SHOPIFY_SHOP_URL'); die();
        if (! getenv('TEST_SHOPIFY_API_KEY') || ! getenv('TEST_SHOPIFY_API_SECRET') || ! getenv('TEST_SHOPIFY_SHOP_URL')) {
            return;
        }

        ShopifyConfigurationService::setup(new Shopify(
            $product->company,
            $product->app,
            $region,
            getenv('TEST_SHOPIFY_API_KEY'),
            getenv('TEST_SHOPIFY_API_SECRET'),
            getenv('TEST_SHOPIFY_SHOP_URL')
        ));
    }
}
