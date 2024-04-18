<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;

class ShopifyConfigurationService
{
    /**
     * Set the shopify credentials into companies custom fields.
     */
    public static function setup(ShopifyDto $data): bool
    {
        $clientCredentialNaming = self::generateCredentialKey($data->company, $data->app, $data->region);

        $configData = [
            CustomFieldEnum::SHOPIFY_API_KEY->value => $data->apiKey,
            CustomFieldEnum::SHOPIFY_API_SECRET->value => $data->apiSecret,
            CustomFieldEnum::SHOP_URL->value => $data->shopUrl,
        ];

        return $data->company->set(
            $clientCredentialNaming,
            $configData
        );
    }

    public static function generateCredentialKey(CompanyInterface $company, AppInterface $app, Regions $region): string
    {
        return CustomFieldEnum::SHOPIFY_API_CREDENTIAL->value . '-' . $app->getId() . '-' . $company->getId() . '-' . $region->getId();
    }

    public static function getProductKey(Products $product, Regions $region): string
    {
        return CustomFieldEnum::SHOPIFY_PRODUCT_ID->value . '-' . $product->app->getId() . '-' . $product->company->getId() . '-' . $region->getId() . '-' . $product->getId();
    }

    public static function getVariantKey(Variants $variant, Regions $region): string
    {
        return CustomFieldEnum::SHOPIFY_VARIANT_ID->value . '-' . $variant->product()->app->getId() . '-' . $variant->company->getId() . '-' . $region->getId() . '-' . $variant->getId();
    }
}
