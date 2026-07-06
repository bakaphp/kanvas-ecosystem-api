<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Types;

use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;

class MetadataType
{
    public function linkedStores(Variants $variant, array $request): array
    {
        // Regions::getDefault falls back to the app-global region (companies_id = 0)
        // when the company has none, gated on the souk_allow_cross_company_variants flag.
        $region = app()->bound(Regions::class)
            ? app(Regions::class)
            : Regions::getDefault($variant->company, $variant->app);

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
