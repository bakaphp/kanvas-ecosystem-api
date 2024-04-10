<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Shopify\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\ShopifyService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use PHPShopify\ShopifySDK;

class CreateProductsAction
{
    public static function createProductShopify(Products $product, Regions $region)
    {
        //////////////// To ignore ///////////////

        $shopify = new ShopifyService(
            app(Apps::class),
            $product->company(),
            $region
        );
        $shopify->config($config);

        //Create a new product
         $productInfo = array(
            "title" => "Burton Custom Freestlye 152",
            "body_html" => "<strong>Good snowboard!<\/strong>",
            "vendor" => "Burton",
            "product_type" => "Snowboard",
         );
         $products = $shopify->Product->post($productInfo);


        dd($products);
        // dd();
    }
}
