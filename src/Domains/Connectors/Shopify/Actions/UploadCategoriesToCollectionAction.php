<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Apps\Models\Apps;
use PHPShopify\ShopifySDK;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;

class UploadCategoriesToCollectionAction
{
    protected ShopifyInventoryService $shopifyService;

    public function __construct(
        public Categories $categories,
        public Apps $app,
        public Warehouses $warehouses,
        public ?string $collectionId = null
    ) {
        $this->shopifyService = new ShopifyInventoryService($app, $categories->company, $warehouses);
    }

    public function execute()
    {
        $products = $this->categories->products()->join('products_warehouses', 'products.id', '=', 'products_warehouses.products_id')
            ->where('products_warehouses.warehouses_id', $this->warehouses->id)
            ->get();

        foreach ($products as $product) {
            $collection = $this->collectionId ?? $this->categories->get("shopify_collection_id");
            $this->shopifyService->attachToCollection($product, $collection);
        }
    }
}
