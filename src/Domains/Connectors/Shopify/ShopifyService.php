<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
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
     */
    public static function shopifySetup(ShopifyDto $data): bool
    {
        $clientCredentialNaming = ShopifyConfigurationService::generateCredentialKey($data->company, $data->app, $data->region);

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

    public function createVariant(Variants $variant): array
    {
        $variantInfo = [
            'product_id' => $variant->product->get(ShopifyConfigurationService::getProductKey($variant->product, $this->region)),
            'option1' => $variant->name,
            'sku' => $variant->sku,
        ];

        $response = $this->shopifySdk->ProductVariant->post($variantInfo);
        return $response;
    }
}
