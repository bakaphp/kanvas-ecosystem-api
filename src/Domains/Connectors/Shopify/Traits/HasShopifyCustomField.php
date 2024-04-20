<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Traits;

use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;

trait HasShopifyCustomField
{
    public function getShopifyId(Regions $region): int|string|null
    {
        return match (true) {
            $this instanceof Variants => $this->get(ShopifyConfigurationService::getVariantKey($this, $region)),
            default => $this->get(ShopifyConfigurationService::getProductKey($this, $region)),
        };
    }

    public function setShopifyId(Regions $region, int|string $shopifyId): void
    {
        match (true) {
            $this instanceof Variants => $this->set(ShopifyConfigurationService::getVariantKey($this, $region), $shopifyId),
            default => $this->set(ShopifyConfigurationService::getProductKey($this, $region), $shopifyId),
        };
    }
}
