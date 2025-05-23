<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

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
            $collection = $this->collectionId ?? $this->categories->get(CustomFieldEnum::SHOPIFY_COLLECTION_ID->value);
            $this->shopifyService->attachToCollection($product, $collection);
        }
    }
}
