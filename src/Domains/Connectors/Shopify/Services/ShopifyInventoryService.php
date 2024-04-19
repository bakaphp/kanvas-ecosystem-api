<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
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
        protected Regions $region
    ) {
        $this->shopifySdk = Client::getInstance($app, $company, $region);
    }

    /**
     * Map and create an product on shopify sdk.
     */
    public function saveProduct(Products $product, StatusEnum $status): array
    {
        $shopifyProductId = $product->get(ShopifyConfigurationService::getProductKey($product, $this->region));

        $productInfo = [
            'title' => $product->name,
            'handle' => $product->slug,
            'body_html' => $product->description,
            'product_type' => $product->productsTypes?->name ?? 'default',
            'vendor' => 'default' , //$product->categ->name , setup vendor as a attribute and add a wy to look for a attribute $product->attribute('vendor')
            'status' => $status->value,
            'published_scope' => 'web',
        ];

        if (! $shopifyProductId) {
            foreach ($product->variants as $variant) {
                $productInfo['variants'][] = $this->mapVariant($variant);
            }

            $response = $this->shopifySdk->Product->post($productInfo);
            $shopifyProductId = $response['id'];
            $product->set(
                ShopifyConfigurationService::getProductKey($product, $this->region),
                $shopifyProductId
            );
        } else {
            $shopifyProduct = $this->shopifySdk->Product($shopifyProductId);
            $response = $shopifyProduct->put($productInfo);
        }

        foreach ($response['variants'] as $shopifyVariant) {
            $variant = $product->variants('sku', $shopifyVariant['sku'])->first();
            $variant->set(
                ShopifyConfigurationService::getVariantKey($variant, $this->region), 
                $shopifyVariant['id']
            );
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
        return [
            'option1' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
        ];
    }

    public function saveVariant(Variants $variant): array
    {
        $shopifyProductVariantId = $variant->get(ShopifyConfigurationService::getVariantKey($variant, $this->region));

        $variantInfo = [
            'product_id' => $variant->product->get(ShopifyConfigurationService::getProductKey($variant->product, $this->region)),
            'option1' => $variant->name,
            'sku' => $variant->sku,
        ];

        if(! $shopifyProductVariantId) {
            $response = $this->shopifySdk->ProductVariant->post($variantInfo);
            $shopifyProductVariantId = $response['id'];

            $variant->set(
                ShopifyConfigurationService::getVariantKey($variant, $this->region), 
                $shopifyProductVariantId
            );
        } else {
            $shopifyProductVariant = $this->shopifySdk->ProductVariant($shopifyProductVariantId);
            $response = $shopifyProductVariant->put($variantInfo);
        }

        return $response;
    }
}
