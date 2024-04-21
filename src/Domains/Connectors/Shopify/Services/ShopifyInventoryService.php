<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use PHPShopify\ShopifySDK;
use Throwable;

class ShopifyInventoryService
{
    protected ShopifySDK $shopifySdk;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected Channels $channel
    ) {
        $this->shopifySdk = Client::getInstance($app, $company, $region);
    }

    /**
     * Map and create an product on shopify sdk.
     */
    public function saveProduct(Products $product, StatusEnum $status): array
    {
        $shopifyProductId = $product->getShopifyId($this->region);

        $productInfo = [
            'title' => $product->name,
            'handle' => $product->slug,
            'body_html' => $product->description,
            'product_type' => $product->productsTypes?->name ?? 'default',
            'vendor' => 'default' , //$product->categ->name , setup vendor as a attribute and add a wy to look for a attribute $product->attribute('vendor')
            'status' => $status->value,
            'published_scope' => 'web',
        ];

        if ($shopifyProductId === null) {
            foreach ($product->variants as $variant) {
                $productInfo['variants'][] = $this->mapVariant($variant);
            }

            $response = $this->shopifySdk->Product->post($productInfo);
            $shopifyProductId = $response['id'];
            $product->setShopifyId($this->region, $shopifyProductId);
        } else {
            $shopifyProduct = $this->shopifySdk->Product($shopifyProductId);
            $response = $shopifyProduct->put($productInfo);
        }

        foreach ($response['variants'] as $shopifyVariant) {
            $variant = $product->variants('sku', $shopifyVariant['sku'])->first();
            $variant->setShopifyId($this->region, $shopifyVariant['id']);
        }

        try {
            $productListing = $this->shopifySdk->ProductListing($shopifyProductId);

            $productListing->put([
                'product_id' => $shopifyProductId,
            ]);
        } catch (Throwable $e) {
            //do nothing
        }

        return $response;
    }

    /**
     * Map the data from the variant into the array
     */
    public function mapVariant(Variants $variant): array
    {
        $channelInfo = $variant->variantChannels()->where('channels_id', $this->channel->getId())->first();
        $warehouseInfo = $channelInfo?->productVariantWarehouse()->first();

        $price = $channelInfo?->price ?? 0;
        $discountedPrice = $channelInfo?->discounted_price ?? 0;
        if ($discountedPrice > 0 && $discountedPrice < $price) {
            $price = $discountedPrice;
            $discountedPrice = $price;
        }

        $shopifyVariantInfo = [
            'option1' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'price' => $price,
            'quantity' => $warehouseInfo?->quantity ?? 0,
            'compare_at_price' => $discountedPrice,
            //'inventory_policy' => 'deny',
        ];

        if ($variant->product->getShopifyId($this->region)) {
            $shopifyVariantInfo['product_id'] = $variant->product->getShopifyId($this->region);
        }

        return $shopifyVariantInfo;
    }

    public function saveVariant(Variants $variant): array
    {
        $shopifyProductVariantId = $variant->getShopifyId($this->region);

        $variantInfo = $this->mapVariant($variant);

        $shopifyProduct = $this->shopifySdk->Product($variant->product->getShopifyId($this->region));
        if ($shopifyProductVariantId === null) {
            $response = $shopifyProduct->Variant->post($variantInfo);
            $shopifyProductVariantId = $response['id'];
            $shopifyProductVariantInventoryId = $response['shopify_inventory_item_id'];

            $variant->setShopifyId($this->region, $shopifyProductVariantId);
        } else {
            unset($variantInfo['option1']);
            $response = $shopifyProduct->Variant($shopifyProductVariantId)->put($variantInfo);
        }

        return $response;
    }

    protected function changeProductStatus(Products $product, StatusEnum $status): array
    {
        $shopifyProductId = $product->getShopifyId($this->region);

        $productInfo = [
            'id' => $shopifyProductId,
            'status' => $status->value,
        ];

        $shopifyProduct = $this->shopifySdk->Product($shopifyProductId);
        $response = $shopifyProduct->put($productInfo);

        return $response;
    }

    public function unPublishProduct(Products $product): array
    {
        return $this->changeProductStatus($product, StatusEnum::ARCHIVED);
    }

    public function publishProduct(Products $product): array
    {
        return $this->changeProductStatus($product, StatusEnum::ACTIVE);
    }
}
