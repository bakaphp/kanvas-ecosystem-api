<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Enums\StatusEnum as ShopifyStatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Workflow\Activity;

class ExportProductToShopifyActivity extends Activity
{
    public $tries = 5;

    public function execute(Products $product, Apps $app, array $params): array
    {
        $response = [];
        $status = Status::where('slug', StatusEnum::ACTIVE->value)
        ->where('apps_id', 0)
        ->first();


        foreach($product->variants as $variant) {

            foreach ($variant->warehouses as $warehouse) {
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

                    $response = $shopifyService->saveProduct($product, ShopifyStatusEnum::ACTIVE);
                }
            }
        }

        return [
            'company' => $product->company->getId(),
            'product' => $product->getId(),
            'shopify_response' => $response,
        ];
    }

}
