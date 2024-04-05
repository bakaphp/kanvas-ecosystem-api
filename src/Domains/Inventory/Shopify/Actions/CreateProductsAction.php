<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Shopify\Actions;

use PHPShopify\ShopifySDK;

class CreateProductsAction
{
    public static function createProductShopify()
    {
        $config = array(
            'ShopUrl' => 'xxxx',
            'ApiKey' => 'xxx',
            'Password' => 'xxx'
        );

        $shopify = new ShopifySDK;
        $shopify->config($config);
        dd($shopify->Product()->get());
        // dd();
    }
}
