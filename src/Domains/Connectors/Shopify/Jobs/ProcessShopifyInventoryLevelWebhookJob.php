<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Connectors\Shopify\Services\ShopifyProductService;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ProcessShopifyInventoryLevelWebhookJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $integrationCompanyId = $this->receiver->configuration['integration_company_id'];
        $integrationCompany = IntegrationsCompany::getById($integrationCompanyId);

        $shopifyVariantInventoryKey = ShopifyConfigurationService::getKey(
            CustomFieldEnum::SHOPIFY_VARIANT_INVENTORY_ID->value,
            $integrationCompany->company,
            $this->receiver->app,
            $integrationCompany->region
        );
        $variant = Variants::getByCustomField(
            $shopifyVariantInventoryKey,
            $this->webhookRequest->payload['inventory_item_id'],
            $integrationCompany->company
        );

        if (! $variant) {
            return [
                'message' => 'Variant not found for ' . $this->webhookRequest->payload['inventory_item_id'],
            ];
        }

        /**
         * @todo look for warehouse by location id
         */
        $warehouses = Warehouses::where('regions_id', $integrationCompany->region_id)
                                ->fromCompany($integrationCompany->company)
                                ->fromApp($this->receiver->app)
                                ->get();

        $shopifyProductService = new ShopifyProductService(
            app: $this->receiver->app,
            company: $integrationCompany->company,
            region: $integrationCompany->region,
            productId: $variant->product->getId()
        );

        $variant->updateQuantityInWarehouse($warehouses, $this->webhookRequest->payload['available']);

        return [
            'message' => 'Inventory level updated successfully',
            'location_id' => $this->webhookRequest->payload['location_id'],
            'inventory_item_id' => $this->webhookRequest->payload['inventory_item_id'],
        ];
    }
}
