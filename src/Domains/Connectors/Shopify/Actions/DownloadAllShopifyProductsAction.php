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

    /**
     * Execute the download action.
     *
     * @param array $params Optional parameters for filtering
     *                      - 'sku' => Filter by specific SKU
     *
     * @return int Total number of products downloaded
     */
    public function execute(array $params = []): int
    {
        $shopify = Client::getInstance(
            $this->app,
            $this->warehouses->company,
            $this->warehouses->region
        );
        $totalRecords = 0;
        $productsToImport = [];
        $shopifyP = $shopify->Product();

        // Check if we're filtering by SKU
        if (isset($params['sku']) && ! empty($params['sku'])) {
            // Search for a specific product by SKU
            $shopifyParams = [
                'query' => 'sku:' . $params['sku'],
            ];

            $shopifyProducts = $shopifyP->get($shopifyParams);

            foreach ($shopifyProducts as $shopifyProduct) {
                $shopifyProductService = new ShopifyProductService(
                    $this->app,
                    $this->warehouses->company,
                    $this->warehouses->region,
                    $shopifyProduct['id'],
                    $this->user,
                    $this->warehouses,
                    $this->channel
                );
                $productsToImport[] = $shopifyProductService->mapProductForImport($shopifyProduct);
                $totalRecords++;
            }
        } else {
            // Download all products (original behavior)
            $firstPage = null;

            do {
                if (! $firstPage && $shopifyP->getNextPageParams()) {
                    // Get next page parameters without 'status' filter
                    $shopifyParams = $shopifyP->getNextPageParams();
                } else {
                    $shopifyParams = $params;
                }

                // Get products based on the current set of parameters
                $currentShopifyProductPage = $shopifyP->get($shopifyParams);

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
                    $productsToImport[] = $shopifyProductService->mapProductForImport($shopifyProduct);
                    $totalRecords++;
                }

                $firstPage = false; // After first fetch, do not reset to initial parameters
            } while ($shopifyP->getNextPageParams());
        }

        // Only dispatch the job if we found products to import
        if ($totalRecords > 0) {
            $jobUuid = Str::uuid()->toString();

            ProductImporterJob::dispatch(
                $jobUuid,
                $productsToImport,
                $this->branch,
                $this->user,
                $this->warehouses->region,
                $this->app
            );
        }

        return $totalRecords;
    }
}
