<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Handlers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Connectors\Interfaces\IntegrationInterfaces;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\ShopifyService;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;

class ShopifyHandler implements IntegrationInterfaces
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Regions $region,
        public array $data
    ) {

    }

    public function setup(): bool
    {
        $shopifyDto = new ShopifyDto(
            company: $this->company,
            app: $this->app,
            region: $this->region,
            apiKey:$this->data['client_id'],
            apiSecret:$this->data['client_secret'],
            shopUrl:$this->data['shop_url'],
        );

        ShopifyService::shopifySetup($shopifyDto);

        return ! empty(Client::getInstance($this->app, $this->company, $shopifyDto->region)->Shop->get());
    }
}
