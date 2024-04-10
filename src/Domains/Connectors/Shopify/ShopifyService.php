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

    public static function shopifySetup(ShopifyDto $data): array
    {
        $clientKeyNaming = CustomFieldEnum::SHOPIFY_API_KEY->value ."-". $data->company->getId() ."-". $data->region->getId();
        $clientSecretNaming = CustomFieldEnum::SHOPIFY_API_KEY->value ."-". $data->company->getId() ."-". $data->region->getId();
        $shopUrlNaming = CustomFieldEnum::SHOPIFY_API_KEY->value ."-". $data->company->getId() ."-". $data->region->getId();

        $data->company->set($clientKeyNaming, $data->api_key);
        $data->company->set($clientKeyNaming, $data->api_secret);
        $data->company->set($clientKeyNaming, $data->shop_url);


        return array([
            "key" => $data->company->get($clientKeyNaming),
            "secret" => $data->company->get($clientSecretNaming),
            "shop" => $data->company->get($shopUrlNaming),
        ]);
    }
}
