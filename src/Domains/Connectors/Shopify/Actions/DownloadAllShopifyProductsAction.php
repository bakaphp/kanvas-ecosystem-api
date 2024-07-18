<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Services\ShopifyProductService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;

class DownloadAllShopifyProductsAction
{
    public function __construct(
        protected Apps $app,
        protected Warehouses $warehouses,
        protected CompaniesBranches $branch,
        protected Users $user,
        protected ?Channels $channel = null
    ) {
    }

    public function execute(array $params = []): int
    {
        $firstPage = null;
        $shopify = Client::getInstance(
            $this->app,
            $this->warehouses->company,
            $this->warehouses->region
        );
        $totalRecords = 0;
        $productsToImport = [];

        $shopifyP = $shopify->Product();

        do {
            if (! $firstPage && $shopifyP->getNextPageParams()) {
                // Get next page parameters without 'status' filter
                $params = $shopifyP->getNextPageParams();
            }

            // Get products based on the current set of parameters
            $currentShopifyProductPage = $shopifyP->get($params);

            foreach ($currentShopifyProductPage as $shopifyProduct) {
                $shopifyProductService = new ShopifyProductService(
                    $this->app,
                    $this->warehouses->company,
                    $this->warehouses->region,
                    $shopifyProduct['id'],
                    $this->user,
                    $this->warehouses,
                    $this->channel
                );
                $productsToImport[] = $shopifyProductService->mapProduct($shopifyProduct);

                $totalRecords++;
            }

            $firstPage = false; // After first fetch, do not reset to initial parameters
        } while ($shopifyP->getNextPageParams());

        $jobUuid = Str::uuid()->toString();

        ProductImporterJob::dispatch(
            $jobUuid,
            $productsToImport,
            $this->branch,
            $this->user,
            $this->warehouses->region,
            $this->app
        );

        return $totalRecords;
    }
}
