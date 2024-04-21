<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;

trait HasShopifyConfiguration
{
    public function setupShopifyConfiguration(Products $product, Regions $region): void
    {
        if (app(Apps::class)->get(CustomFieldEnum::SHOPIFY_API_KEY->value) !== null
            && app(Apps::class)->get(CustomFieldEnum::SHOPIFY_API_SECRET->value) !== null
            && app(Apps::class)->get(CustomFieldEnum::SHOP_URL->value) !== null) {

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
