<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Products\Models\Products;

class SyncShopifyProductAction
{
    public function __construct(
        protected Products $product,
    ) {
    }

    public function execute()
    {
        foreach ($this->product->variants as $variant) {
            $regions = $variant->warehouses->map(function ($warehouses) {
                return $warehouses->regions;
            });
        }

        foreach ($regions as $region) {
            $shopifyService = new ShopifyInventoryService($this->product->app, $this->product->company, $region);
            $shopifyService->saveProduct($this->product, StatusEnum::ACTIVE);
        }
    }
}
