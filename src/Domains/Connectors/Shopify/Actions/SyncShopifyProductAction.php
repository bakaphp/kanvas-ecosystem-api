<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Channels\Models\Channels;
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
            $variant->warehouses->map(function ($warehouses) use ($variant) {
                $shopifyService = new ShopifyInventoryService($variant->app, $variant->company, $warehouses);
                $shopifyService->saveProduct($variant->product, StatusEnum::ACTIVE);
            });
        }
    }
}
