<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Inventory\Variants\Models\Variants;
use NetSuite\Classes\InventoryItem;

/**
 * This action handles the synchronization of the NetSuite Products,
 * from a specific customer or company.
 */
class SyncNetSuiteProductsAction
{
    protected NetSuiteCustomerService $service;
    protected NetSuiteProductService $productService;
    protected NetSuiteProductSearchService $searchService;
    protected bool $shouldUseLegacySearch = false;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
        protected CompanyInterface $buyerCompany
    ) {
        $this->service = new NetSuiteCustomerService($app, $mainAppCompany);
        $this->productService = new NetSuiteProductService($app, $mainAppCompany);
        $this->shouldUseLegacySearch = $this->app->get(ConfigurationEnum::NET_SUITE_USE_LEGACY_PRODUCT_SEARCH->value);
        $this->searchService = new NetSuiteProductSearchService($app, $mainAppCompany);
    }

    public function execute(): array
    {
        $customerId = $this->buyerCompany->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value);

        if (! $customerId) {
            throw new Exception('Company not linked to NetSuite');
        }

        $customerInfo = $this->service->getCustomerById($customerId);

        $listOrProductVariantsBarCodeIds = $customerInfo->itemPricingList?->itemPricing ?? [];

        $totalProcessed = 0;
        $setMinimumQuantity = $this->app->get(ConfigurationEnum::NET_SUITE_MINIMUM_PRODUCT_QUANTITY->value);
        $defaultWarehouse = $this->mainAppCompany->get(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value);
        $missed = [];

        foreach ($listOrProductVariantsBarCodeIds as $bardCodeId) {
            $config = null;
            $variant = Variants::fromApp($this->app)
                ->fromCompany($this->mainAppCompany)
                ->where('barcode', $bardCodeId->item->name)
                ->first();

            if (! $variant) {
                $missed[] = $bardCodeId->item->name;
                $totalProcessed++;
                continue;
            }

            $variantWarehouse = $variant->variantWarehouses()->firstOrFail();

            $searchNetsuiteProductInfo = $this->productService->searchProductByItemNumber($variant->barcode);
            $netsuiteProductInfo = $this->productService->getProductById($searchNetsuiteProductInfo[0]->internalId);
            /**
             * @todo , this logic to update the quantity and price should be moved to a dedicated action / workflow
             */

            try {
                $warehouseOptions = $this->getWarehouseOptions($netsuiteProductInfo);
                $locationId = $variantWarehouse->get(CustomFieldEnum::NET_SUITE_LOCATION_ID->value) ?? $defaultWarehouse;

                $product = $this->shouldUseLegacySearch
                ? $this->legacySearchProductByItemNumber($netsuiteProductInfo, $locationId)
                : $this->customSearchProductByItemNumber($variant->barcode, $locationId);

                if (empty($product)) {
                    $missed[] = $bardCodeId->item->name;
                    $totalProcessed++;
                    continue;
                }

                $minimumQuantity = $product['minimum_quantity'];

                $config = [
                    'map_price' => $product["map_price"],
                    ...($minimumQuantity && $setMinimumQuantity ? ['minimum_quantity' => $minimumQuantity] : []),
                ];

                $variantWarehouse->quantity = $product["quantity_available"];
                $variantWarehouse->price = $warehouseOptions['price'] ?? 0;

                $variantWarehouse->config = $config;
                $variantWarehouse->saveOrFail();

                $variant->addAttributes($this->mainAppCompany->user, [
                    [
                        'name' => 'color_code',
                        'value' => $product['color_code'],
                    ],
                ]);
            } catch (Exception $e) {
                //$config['minimum_quantity'] = 0;
                $missed[] = $bardCodeId->item->name;
            }
            $totalProcessed++;
        }

        return [
            'items' => $listOrProductVariantsBarCodeIds,
            'total_items' => count($listOrProductVariantsBarCodeIds),
            'total_processed' => $totalProcessed,
            'total_missed' => count($missed),
            'products_not_found' => $missed,
        ];
    }

    private function getWarehouseOptions(
        InventoryItem $netsuiteProductInfo
    ): array {
        $config = [];

        try {
            $config['price'] = $this->productService->getProductPrice($netsuiteProductInfo);
            $config['minimum_quantity'] = $netsuiteProductInfo->minimumQuantity;
        } catch (Exception) {
            return $config;
        }

        return $config;
    }

    private function legacySearchProductByItemNumber(
        InventoryItem $netsuiteProductInfo,
        ?string $locationId = null
    ): array {
        $mapPrice = (float) $this->productService->getCustomField($netsuiteProductInfo, CustomFieldEnum::NET_SUITE_MAP_PRICE_CUSTOM_FIELD->value);
        $colorCode = $this->productService->getCustomField($netsuiteProductInfo, CustomFieldEnum::NET_SUITE_COLOR_CODE_CUSTOM_FIELD->value);
        $quantity = 0;

        try {
            $quantity = $this->productService->getInventoryQuantityByLocation($netsuiteProductInfo, $locationId);
        } catch (Exception) {
            return [];
        }

        return [
            'map_price' => $mapPrice,
            'color_code' => $colorCode,
            'minimum_quantity' => $netsuiteProductInfo->minimumQuantity,
            'quantity_available' => $quantity,

        ];
    }


    private function customSearchProductByItemNumber(string $itemNumber, ?string $locationId = null): array
    {
        $searchProduct = $this->searchService->searchProductByItemNumber($itemNumber, $locationId);

        if (count($searchProduct) === 0) {
            return [];
        }

        return [
            'map_price' => (float) $searchProduct[0]['mapPrice'],
            'color_code' => $searchProduct[0]['colorCode'],
            'minimum_quantity' => $searchProduct[0]['minimumQuantity'],
            'quantity_available' => $searchProduct[0]['quantityAvailable'],
        ];
    }
}
