<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use PHPShopify\ShopifySDK;

class ShopifyService
{
    protected ShopifySDK $shopifySdk;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region
    ) {
        $this->shopifySdk = Client::getInstance($app, $company, $region);
    }

    /**
     * Set the shopify credentials into companies custom fields.
     *
     * @param ShopifyDto $data
     * @return bool
     */
    public static function shopifySetup(ShopifyDto $data): bool
    {
        $clientCredentialNaming = CustomFieldEnum::SHOPIFY_API_CREDENTIAL->value ."-". $data->company->getId() ."-". $data->region->getId();

        $configData = [
            CustomFieldEnum::SHOPIFY_API_KEY->value => $data->apiKey,
            CustomFieldEnum::SHOPIFY_API_SECRET->value => $data->apiSecret,
            CustomFieldEnum::SHOP_URL->value => $data->shopUrl,
        ];

        return $data->company->set($clientCredentialNaming, $configData);
    }

    /**
     * Map and create an product on shopify sdk.
     *
     * @param Products $product
     * @param StatusEnum $status
     * @return array
     */
    public function createProduct(Products $product, StatusEnum $status): array
    {
        $productInfo = [
            'title' => $product->name,
            'body_html' => $product->description,
            'product_type' => $product->productsTypes->name,
            'status' => $status->value
        ];

        foreach($product->variants as $variant)
        {
            $productInfo['variants'][] = $this->mapVariant($variant);
        }

        return $this->shopifySdk->Product->post($productInfo);
    }

    /**
     * Map the data from the variant into the array
     *
     * @param Variants $variant
     * @return array
     */
    public function mapVariant(Variants $variant): array
    {
        return [
            'option1' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
        ];
    }
}
