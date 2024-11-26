<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\AddEntityIntegrationHistoryAction;
use Kanvas\Workflow\Integrations\DataTransferObject\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

class DeleteVariantFromShopifyActivity extends KanvasActivity
{
    public function execute(Variants $variant, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $response = [];
        $exception = null;
        $status = Status::where('slug', StatusEnum::ACTIVE->value)
        ->where('apps_id', 0)
        ->first();

        foreach ($variant->warehouses as $warehouse) {

            $integrationCompany = IntegrationsCompany::getByIntegration(
                company: $variant->product->company,
                status: $status,
                region: $warehouse->region,
                name: IntegrationsEnum::SHOPIFY->value
            );

            if ($integrationCompany) {
                // Sending warehouses instead of region, until integration is migrated on all the code.
                $shopifyService = new ShopifyInventoryService(
                    app: $app,
                    company: $variant->product->company,
                    warehouses: $warehouse
                );
                $shopifyVariantId = $variant->getShopifyId($warehouse->regions);

                if(!$shopifyVariantId) {
                    return [];
                }

                try {
                    $response = $shopifyService->deleteVariant($variant);
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
                    entity: $variant,
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

        return [
            'shopify_response' => $response ?? [],
        ];
    }
}
