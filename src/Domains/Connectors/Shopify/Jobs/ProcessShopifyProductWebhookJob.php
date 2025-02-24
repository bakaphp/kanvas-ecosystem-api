<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Baka\Support\Str;
use Kanvas\Connectors\Shopify\Services\ShopifyProductService;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ProcessShopifyProductWebhookJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $integrationCompanyId = $this->receiver->configuration['integration_company_id'];
        $integrationCompany = IntegrationsCompany::getById($integrationCompanyId);

        $warehouses = Warehouses::where('regions_id', $integrationCompany->region_id)
                                ->fromCompany($integrationCompany->company)
                                ->fromApp($this->receiver->app)
                                ->get();

        $shopifyProductService = new ShopifyProductService(
            app: $this->receiver->app,
            company: $integrationCompany->company,
            region: $integrationCompany->region,
            productId: $this->webhookRequest->payload['id'],
        );

        $mappedProduct = $shopifyProductService->mapProductForImport($this->webhookRequest->payload);
        foreach ($mappedProduct['variants'] as $key => $variant) {
            $mappedProduct['variants'][$key]['warehouses'] = $warehouses->toArray();
        }

        $jobUuid = Str::uuid()->toString();

        ProductImporterJob::dispatch(
            jobUuid: $jobUuid,
            importer: [$mappedProduct],
            branch: $integrationCompany->company->branch,
            user: $this->receiver->user,
            region: $integrationCompany->region,
            app: $this->receiver->app,
            runWorkflow: false
        );

        return [
            'message' => 'Product synced successfully',
            'shopify_id' => $this->webhookRequest->payload['id'],
            'product_name' => $mappedProduct['name'],
        ];
    }
}
