<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Inventory\Regions\Models\Regions;
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
}
