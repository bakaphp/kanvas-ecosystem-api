<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Inventory\Variants\Models\Variants;

class PullNetSuiteProductStockAction
{
    protected NetSuiteCustomerService $service;
    protected NetSuiteProductService $productService;
    protected NetSuiteProductSearchService $searchService;
    protected bool $shouldUseLegacySearch = false;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
        protected UserInterface $user
    ) {
        $this->service = new NetSuiteCustomerService($app, $mainAppCompany);
        $this->searchService = new NetSuiteProductSearchService($app, $mainAppCompany);
    }

    public function execute(string|int $savedSearchID = 576, ?array $barcodeFilter = null): array
    {
        $products = $this->searchService->searchWithSavedSearch($savedSearchID);

        if (count($products) === 0) {
            return [];
        }

        if (empty($products)) {
            return [
                'company' => $this->mainAppCompany->getId(),
                'app' => $this->app->getId(),
                'error' => 'Products not found',
                'searchId' => $savedSearchID,
                'savedSearchTotal' => count($products),
                'processed' => 0,
                'not_found_in_kanvas' => 0,
                'not_found_in_netsuite' => 0,
            ];
        }

        // Index NetSuite products by itemId for fast lookup
        $netsuiteIndex = [];
        foreach ($products as $product) {
            $netsuiteIndex[$product['itemId']] = $product;
        }

        $results = [
            "processed" => [],
            "not_found_in_kanvas" => [],
            "not_found_in_netsuite" => [],
            "update_failed" => []
        ];

        // Determine which products to process
        $productsToProcess = $barcodeFilter ?? array_keys($netsuiteIndex);

        foreach ($productsToProcess as $barcode) {
            // Check if product exists in NetSuite results
            if (! isset($netsuiteIndex[$barcode])) {
                $results["not_found_in_netsuite"][] = $barcode;
                continue;
            }

            $netsuiteVariant = $netsuiteIndex[$barcode];

            $variant = Variants::fromApp($this->app)
                ->fromCompany($this->mainAppCompany)
                ->where('barcode', $barcode)
                ->first();

            if (! $variant) {
                $results["not_found_in_kanvas"][] = $barcode;
                continue;
            }

            $variantWarehouse = $variant->variantWarehouses()->first();

            if (! $variantWarehouse) {
                $results["not_found_in_kanvas"][] = $barcode;
                continue;
            }

            try {
                $variantWarehouse->quantity = $netsuiteVariant['quantityAvailable'] ?? 0;
                $variantWarehouse->saveOrFail();
                $results["processed"][] = $barcode;
            } catch (Exception $e) {
                $results["update_failed"][] = [
                    'barcode' => $barcode,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'company' => $this->mainAppCompany->getId(),
            'searchId' => $savedSearchID,
            'savedSearchTotal' => count($products),
            'totalToProcess' => count($productsToProcess),
            'processed' => count($results["processed"]),
            'processedList' => $results["processed"],
            'not_found_in_kanvas' => count($results["not_found_in_kanvas"]),
            'not_found_in_kanvas_list' => $results["not_found_in_kanvas"],
            'not_found_in_netsuite' => count($results["not_found_in_netsuite"]),
            'not_found_in_netsuite_list' => $results["not_found_in_netsuite"],
            'update_failed' => count($results["update_failed"]),
            'update_failed_list' => $results["update_failed"],
        ];
    }
}
