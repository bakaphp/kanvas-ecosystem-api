<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;
use Kanvas\Connectors\Shopify\ShopifyService;

class ShopifyHandler extends BaseIntegration
{
    public function setup(): bool
    {
        $shopifyDto = new ShopifyDto(
            company: $this->company,
            app: $this->app,
            region: $this->region,
            apiKey: $this->data['client_id'],
            apiSecret: $this->data['client_secret'],
            shopUrl: $this->data['shop_url'],
        );

        ShopifyService::shopifySetup($shopifyDto);

        return ! empty(Client::getInstanceValidation($this->app, $this->company, $shopifyDto->region)->Shop->get());
    }
}
