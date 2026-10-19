<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Contracts\DefaultRule;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Connectors\Shopify\ShopifyService;
use Kanvas\Connectors\Shopify\Workflows\Activities\PushOrderActivity;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Override;

class ShopifyHandler extends BaseIntegration
{
    #[Override]
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

        return ! empty(Client::getInstance($this->app, $this->company, $shopifyDto->region)->Shop->get());
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [ShopifyConfigurationService::generateCredentialKey($this->company, $this->app, $this->region)];
    }

    #[Override]
    public function defaultRules(): array
    {
        return [
            new DefaultRule(
                trigger: WorkflowEnum::PUSH,
                model: Order::class,
                activity: PushOrderActivity::class,
                name: 'Shopify push order',
            ),
        ];
    }
}
