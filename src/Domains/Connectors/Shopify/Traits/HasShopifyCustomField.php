<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Traits;

use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;

trait HasShopifyCustomField
{
    public function getShopifyId(Regions $region, ?string $part = null): int|string|null
    {
        return match (true) {
            $this instanceof Variants => $this->get(ShopifyConfigurationService::getVariantKey($this, $region)),
            $this instanceof Order => $this->get(ShopifyConfigurationService::getOrderKey($region)),
            default => $this->get(ShopifyConfigurationService::getProductKey($this, $region) . $part),
        };
    }

    public function delShopifyId(Regions $region, ?string $part = null): bool
    {
        return match (true) {
            $this instanceof Variants => $this->del(ShopifyConfigurationService::getVariantKey($this, $region)),
            $this instanceof Order => $this->del(ShopifyConfigurationService::getOrderKey($region)),
            default => $this->del(ShopifyConfigurationService::getProductKey($this, $region) . $part),
        };
    }

    public function setShopifyId(Regions $region, int|string $shopifyId, ?string $part = null): void
    {
        match (true) {
            $this instanceof Variants => $this->set(ShopifyConfigurationService::getVariantKey($this, $region), $shopifyId),
            $this instanceof Order => $this->set(ShopifyConfigurationService::getOrderKey($region), $shopifyId),
            default => $this->set(ShopifyConfigurationService::getProductKey($this, $region) . $part, $shopifyId),
        };
    }

    public function setInventoryId(Regions $region, int|string $inventoryId): void
    {
        match (true) {
            $this instanceof Variants => $this->set(ShopifyConfigurationService::getVariantInventoryKey($this, $region), $inventoryId),
            default => throw new ValidationException('Only variants can have inventory id'),
        };
    }

    public function getInventoryId(Regions $region): int|string|null
    {
        return match (true) {
            $this instanceof Variants => $this->get(ShopifyConfigurationService::getVariantInventoryKey($this, $region)),
            default => null,
        };
    }

    public function getShopifyUrl(Regions $region): ?string
    {
        $key = CustomFieldEnum::SHOPIFY_PRODUCT_URL->value . '-' . $region->getId();

        return match (true) {
            $this instanceof Variants => $this->product->get($key),
            default => $this->get($key),
        };
    }
}
