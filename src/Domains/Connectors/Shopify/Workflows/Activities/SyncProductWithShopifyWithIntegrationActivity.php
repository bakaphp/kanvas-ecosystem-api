<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Enums\StatusEnum as ShopifyStatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\AddEntityIntegrationHistoryAction;
use Kanvas\Workflow\Integrations\DataTransferObject\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\KanvasActivities;
use Throwable;

class SyncProductWithShopifyWithIntegrationActivity extends KanvasActivities
{
    public function execute(Products $product, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $response = [];
        $exception = null;
        $status = Status::where('slug', StatusEnum::ACTIVE->value)
        ->where('apps_id', 0)
        ->first();

        foreach ($product->variants as $variant) {
            foreach ($variant->warehouses as $warehouse) {
                /**
                 * @todo create a ActivityWorkflow so we don`t have to call
                 * getting integration and its history
                 */
                $integrationCompany = IntegrationsCompany::getByIntegration(
                    company: $product->company,
                    status: $status,
                    region: $warehouse->region,
                    name: IntegrationsEnum::SHOPIFY->value
                );

                if ($integrationCompany) {
                    // Sending warehouses instead of region, until integration is migrated on all the code.
                    $shopifyService = new ShopifyInventoryService(
                        app: $app,
                        company: $product->company,
                        warehouses: $warehouse
                    );

                    try {
                        $response = $shopifyService->saveProduct($product, ShopifyStatusEnum::ACTIVE);
                        $historyResponse = json_encode($response);
                        $status = Status::where('slug', StatusEnum::CONNECTED->value)
                        ->where('apps_id', 0)
                        ->first();
                    } catch (Throwable $exception) {
                        $status = Status::where('slug', StatusEnum::FAILED->value)
                        ->where('apps_id', 0)
                        ->first();
                    }

                    $dto = new EntityIntegrationHistory(
                        app: $app,
                        integrationCompany: $integrationCompany,
                        status: $status,
                        entity: $product,
                        response: $historyResponse ?? null,
                        exception: $exception,
                        workflowId: $this->workflowId()
                    );

                    (new AddEntityIntegrationHistoryAction(
                        dto: $dto,
                        app: $app,
                        status: $status
                    ))->execute();
                }
            }
        }

        return [
            'company' => $product->company->getId(),
            'product' => $product->getId(),
            'shopify_response' => $response ?? [],
        ];
    }
}
