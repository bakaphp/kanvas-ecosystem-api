<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Products\Models\Products;

class SyncProductWithShopifyAction
{
    public function __construct(
        protected Products $product,
    ) {
    }

    public function execute(): array
    {
        $shopifyResponse = [];
        foreach ($this->product->variants as $variant) {
            $variant->warehouses->map(function ($warehouses) use ($variant) {
                $shopifyService = new ShopifyInventoryService($variant->app, $variant->company, $warehouses);
                $shopifyResponse[] = $shopifyService->saveProduct($variant->product, StatusEnum::ACTIVE);
            });
        }

        return $shopifyResponse;
    }
}
