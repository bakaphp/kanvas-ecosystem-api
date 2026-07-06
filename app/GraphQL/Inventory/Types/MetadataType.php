<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Types;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Variants\Models\Variants;

class MetadataType
{
    public function linkedStores(Variants $variant, array $request): array
    {
        try {
            $region = app()->bound(Regions::class)
                ? app(Regions::class)
                : RegionRepository::getDefault($variant->company, $variant->app);
        } catch (ModelNotFoundException) {
            $region = null;
        }

        if (! $region instanceof Regions) {
            return [
                'shopify' => [
                    'id' => null,
                    'inventory_id' => null,
                    'url' => null,
                ],
            ];
        }

        return [
            'shopify' => [
                'id' => $variant->getShopifyId($region),
                'inventory_id' => $variant->getInventoryId($region),
                'url' => $variant->getShopifyUrl($region),
            ],
        ];
    }
}
