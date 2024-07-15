<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Types;

use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;

class MetadataType
{
    public function linkedStores(Variants $variant, array $request): array
    {
        $region = ! app()->bound(Regions::class) ? $variant->company->defaultRegion : app(Regions::class);

        return [
            'shopify' => [
                'id' => $variant->getShopifyId($region),
                'inventory_id' => $variant->getInventoryId($region),
                'url' => $variant->getShopifyUrl($region),
                //'product_id' => $variant->product->getShopifyId($region),
            ],
        ];
    }
}
