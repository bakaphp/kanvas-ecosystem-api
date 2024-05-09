<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Types;

use Kanvas\Inventory\Variants\Models\Variants;

class MetadataType
{
    public function linkedStores(Variants $variant, array $request): array
    {
        return [
            'shopify' => $variant->getShopifyId(),
        ];
    }
}
