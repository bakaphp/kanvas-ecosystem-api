<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Baka\Support\Str;
use Kanvas\Connectors\Shopify\Actions\SyncShopifyOrderAction;
use Kanvas\Connectors\Shopify\Services\ShopifyProductService;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ProcessShopifyProductsWebhookJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $integrationCompanyId = $this->receiver->configuration['integration_company_id'];
        $integrationCompany = IntegrationsCompany::getById($integrationCompanyId);

        $warehouses = Warehouses::where('regions_id',$integrationCompany->region_id)
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
        $mappedProduct['variants']['warehouses'] = $warehouses->toArray();

        $jobUuid = Str::uuid()->toString();

        ProductImporterJob::dispatch(
            $jobUuid,
            [$mappedProduct],
            $integrationCompany->company->branch,
            $this->receiver->user,
            $integrationCompany->region,
            $this->receiver->app
        );

        return [
            'message' => 'Product synced successfully',
            'shopify_id' => $this->webhookRequest->payload['id'],
            'product_name' => $mappedProduct['name']
        ];
    }
}
