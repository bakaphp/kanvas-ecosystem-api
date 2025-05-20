<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Services\ShopifyOrderService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushOrderActivity extends KanvasActivity
{
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = Companies::getById($entity->companies_id);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::SHOPIFY,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($params, $company) {
                $orderId = $params['entity_id'] ?? null;
                $status = $params['order_status'] ?? null;
                $trackingNumber = $params['tracking_number'] ?? null;
                $warehouse = Warehouses::getDefault($company, $app);

                $shopifyOrderService = new ShopifyOrderService(
                    $app,
                    $company,
                    $warehouse
                );

                $trackingNumberResult = $shopifyOrderService->addTrackingToOrder(
                    orderId: $orderId,
                    trackingNumber: $trackingNumber,
                    // status: $status,
                );

                $fulfillmentStatus = $shopifyOrderService->changeFulfillmentStatus(
                    orderId: $orderId,
                    status: $status,
                );

                return [
                    'status' => 'success',
                    'tracking_number' => $trackingNumberResult,
                    'fulfillment_status' => $fulfillmentStatus,
                    'order_id' => $orderId,
                ];
            },
            company: $company,
        );
    }
}
