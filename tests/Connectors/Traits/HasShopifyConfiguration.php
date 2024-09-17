<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

trait HasShopifyConfiguration
{
    public function setupShopifyConfiguration(Products $product, Warehouses $warehouses): void
    {
        if (! getenv('TEST_SHOPIFY_API_KEY') || ! getenv('TEST_SHOPIFY_API_SECRET') || ! getenv('TEST_SHOPIFY_SHOP_URL')) {
            throw new Exception('Missing Shopify configuration');
        }

        ShopifyConfigurationService::setup(new Shopify(
            $product->company,
            $product->app,
            $warehouses->regions,
            getenv('TEST_SHOPIFY_API_KEY'),
            getenv('TEST_SHOPIFY_API_SECRET'),
            getenv('TEST_SHOPIFY_SHOP_URL')
        ));
    }

    public function setupShopifyIntegration(Products $product, Regions $region)
    {
        $credentials = [
            'client_id' => getenv('TEST_SHOPIFY_API_KEY'),
            'client_secret' => getenv('TEST_SHOPIFY_API_SECRET'),
            'shop_url' => getenv('TEST_SHOPIFY_SHOP_URL'),
        ];

        $integration = Integrations::first();

        $integrationDto = new IntegrationsCompany(
            integration: $integration,
            region: $region,
            company: $product->company,
            config: $credentials,
            app: app(Apps::class)
        );

        $status = Status::where('slug', StatusEnum::ACTIVE->value)
        ->where('apps_id', 0)
        ->first();

        // for the time being this can only work with shopify integration.
        // we need to figure out how to standard is it.
        return (new CreateIntegrationCompanyAction($integrationDto, auth()->user(), $status))->execute();
    }
}
