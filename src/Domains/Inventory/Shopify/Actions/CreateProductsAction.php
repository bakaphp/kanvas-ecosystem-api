<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Shopify\Actions;

use PHPShopify\ShopifySDK;

class CreateProductsAction
{
    public static function createProductShopify()
    {
        $config = array(
            'ShopUrl' => 'bldstage.shopify.com',
            'ApiKey' => 'ea47032c39eda36e3e91004a9c923e0c',
            'Password' => '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'
        );

        $shopify = new ShopifySDK;
        $shopify->config($config);
        dd($shopify->Product()->get());
        // dd();
    }
}
