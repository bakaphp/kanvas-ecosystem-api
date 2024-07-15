<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use PHPShopify\ShopifySDK;
use Throwable;

class ShopifyInventoryService
{
    protected ShopifySDK $shopifySdk;
    protected ShopifyImageService $shopifyImageService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Warehouses $warehouses,
    ) {
        $this->shopifySdk = Client::getInstance($app, $company, $warehouses->regions);
        $this->shopifyImageService = new ShopifyImageService($app, $company, $warehouses->regions);
    }

    /**
     * Map and create an product on shopify sdk.
     */
    public function saveProduct(Products $product, StatusEnum $status, ?Channels $channel = null): array
    {
        $shopifyProductId = $product->getShopifyId($this->warehouses->regions);

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
            $product->setShopifyId($this->warehouses->regions, $shopifyProductId);

            foreach ($response['variants'] as $shopifyVariant) {
                $variant = $product->variants('sku', $shopifyVariant['sku'])->first();
                if ($variant->getShopifyId($this->warehouses->regions) === null) {
                    $variant->setShopifyId($this->warehouses->regions, $shopifyVariant['id']);
                }
            }
        } else {
            $shopifyProduct = $this->shopifySdk->Product($shopifyProductId);
            $response = $shopifyProduct->put($productInfo);

            foreach ($product->variants as $variant) {
                $this->saveVariant($variant, $channel);
            }
        }

        try {
            $productListing = $this->shopifySdk->ProductListing($shopifyProductId);

            $productListing->put([
                'product_id' => $shopifyProductId,
            ]);
        } catch (Throwable $e) {
            //do nothing
        }

        $this->shopifyImageService->processEntityImage($product);

        return $response;
    }

    /**
     * Map the data from the variant into the array
     */
    public function mapVariant(Variants $variant, ?Channels $channel = null): array
    {
        $warehouseInfo = $variant->variantWarehouses()->where('warehouses_id', $this->warehouses->getId());

        if ($channel) {
            $channelInfo = $variant->variantChannels()->where('channels_id', $channel->getId())->first();

            $price = $channelInfo?->price ?? 0;
            $discountedPrice = $channelInfo?->discounted_price ?? 0;
            if ($discountedPrice > 0 && $discountedPrice < $price) {
                $price = $discountedPrice;
                $discountedPrice = $price;
            }
        } else {
            $price = $warehouseInfo?->price ?? 0;
        }

        $quantity = $warehouseInfo?->quantity ?? 0;
        $shopifyVariantInfo = [
            'option1' => $variant->sku ?? $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'price' => $price,
            'quantity' => $quantity,
            'compare_at_price' => $discountedPrice ?? 0,
            'inventory_policy' => 'deny',
        ];

        if ($quantity > 0) {
            $this->setStock($variant, $channel);
        }

        if ($variant->product->getShopifyId($this->warehouses->regions)) {
            $shopifyVariantInfo['product_id'] = $variant->product->getShopifyId($this->warehouses->regions);
        }

        return $shopifyVariantInfo;
    }

    public function saveVariant(Variants $variant, Channels $channel = null): array
    {
        $shopifyProductVariantId = $variant->getShopifyId($this->warehouses->regions);

        $variantInfo = $this->mapVariant($variant, $channel);

        $shopifyProduct = $this->shopifySdk->Product($variant->product->getShopifyId($this->warehouses->regions));
        if ($shopifyProductVariantId === null) {
            $response = $shopifyProduct->Variant->post($variantInfo);
            $shopifyProductVariantId = $response['id'];
            $shopifyProductVariantInventoryId = $response['inventory_item_id'];

            $variant->setShopifyId($this->warehouses->regions, $shopifyProductVariantId);
            $variant->setInventoryId($this->warehouses->regions, $shopifyProductVariantInventoryId);
        } else {
            unset($variantInfo['option1']);
            $response = $shopifyProduct->Variant($shopifyProductVariantId)->put($variantInfo);

            if ($variant->getInventoryId($this->warehouses->regions) === null) {
                $variant->setInventoryId($this->warehouses->regions, $response['inventory_item_id']);
            }
        }

        $this->shopifyImageService->processEntityImage($variant);

        return $response;
    }

    public function setStock(Variants $variant, Channels $channel, bool $isAdjustment = false): int
    {
        $shopifyVariant = $this->shopifySdk->ProductVariant($variant->getShopifyId($this->warehouses->regions));

        $channelInfo = $variant->variantChannels()->first();
        $warehouseInfo = $channelInfo?->productVariantWarehouse()->first();

        $shopifyVariant->put([
            'inventory_management' => 'shopify',
        ]);

        $defaultLocation = $this->shopifySdk->Shop->get()['primary_location_id'];

        if ($isAdjustment) {
            $shopifyInventory = $this->shopifySdk->InventoryLevel->adjust([
                'inventory_item_id' => $variant->getInventoryId($this->warehouses->regions),
                'location_id' => $defaultLocation,
                'available_adjustment' => $warehouseInfo?->quantity ?? 0,
            ]);
        } else {
            $shopifyInventory = $this->shopifySdk->InventoryLevel->set([
                'inventory_item_id' => $variant->getInventoryId($this->warehouses->regions),
                'location_id' => $defaultLocation,
                'available' => $warehouseInfo?->quantity ?? 0,
            ]);
        }

        return (int) $shopifyInventory['available'];
    }

    protected function changeProductStatus(Products $product, StatusEnum $status): array
    {
        $shopifyProductId = $product->getShopifyId($this->warehouses->regions);

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
