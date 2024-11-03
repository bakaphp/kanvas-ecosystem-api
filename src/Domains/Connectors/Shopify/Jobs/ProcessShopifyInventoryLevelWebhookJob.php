<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Connectors\Shopify\Services\ShopifyProductService;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\AddEntityIntegrationHistoryAction;
use Kanvas\Workflow\Integrations\DataTransferObject\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Throwable;

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
                                ->first();

        if (! $warehouses) {
            return [
                'message' => 'Warehouse not found for ' . $integrationCompany->region_id,
            ];
        }

        $shopifyProductService = new ShopifyProductService(
            app: $this->receiver->app,
            company: $integrationCompany->company,
            region: $integrationCompany->region,
            productId: $variant->product->getId()
        );

        $status = Status::getDefaultStatusByName(StatusEnum::CONNECTED->value);

        $dto = new EntityIntegrationHistory(
            app: $this->receiver->app,
            integrationCompany: $integrationCompany,
            status: $status,
            entity: $variant->product,
            response: $this->webhookRequest->payload
        );

        try {
            $variant->updateQuantityInWarehouse($warehouses, $this->webhookRequest->payload['available']);
        } catch (Throwable $e) {
            $status = Status::getDefaultStatusByName(StatusEnum::FAILED->value);
            $dto->exception = $e;
        }

        (new AddEntityIntegrationHistoryAction(
            dto: $dto,
            app: $this->receiver->app,
            status: $status
        ))->execute();

        return [
            'message' => $dto->exception == null ? 'Inventory level updated successfully' : 'Failed to update inventory level',
            'location_id' => $this->webhookRequest->payload['location_id'],
            'inventory_item_id' => $this->webhookRequest->payload['inventory_item_id'],
        ];
    }
}
