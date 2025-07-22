<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use NetSuite\Classes\InventoryItem;

/**
 * This action handles the synchronization of the NetSuite Products,
 * from a specific customer or company.
 */
class SyncNetSuiteProductsAction
{
    protected NetSuiteCustomerService $service;
    protected NetSuiteProductService $productService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
        protected CompanyInterface $buyerCompany
    ) {
        $this->service = new NetSuiteCustomerService($app, $mainAppCompany);
        $this->productService = new NetSuiteProductService($app, $mainAppCompany);
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

                continue;
            }

            $variantWarehouse = $variant->variantWarehouses()->firstOrFail();

            $searchNetsuiteProductInfo = $this->productService->searchProductByItemNumber($variant->barcode);
            $netsuiteProductInfo = $this->productService->getProductById($searchNetsuiteProductInfo[0]->internalId);

            /**
             * @todo , this logic to update the quantity and price should be moved to a dedicated action / workflow
             */

            try {
                $warehouseOptions = $this->getWarehouseOptions($netsuiteProductInfo, $variantWarehouse, $defaultWarehouse);

                $mapPrice = (float) $this->productService->getCustomField($netsuiteProductInfo, CustomFieldEnum::NET_SUITE_MAP_PRICE_CUSTOM_FIELD->value);
                $colorCode = $this->productService->getCustomField($netsuiteProductInfo, CustomFieldEnum::NET_SUITE_COLOR_CODE_CUSTOM_FIELD->value);

                $config = [
                    'map_price' => $mapPrice,
                    ...(isset($warehouseOptions['minimum_quantity']) && $setMinimumQuantity ? ['minimum_quantity' => $warehouseOptions['minimum_quantity']] : []),
                ];

                if (isset($warehouseOptions['quantity']) && $warehouseOptions['quantity'] !== null) {
                    $variantWarehouse->quantity = $warehouseOptions['quantity'];
                    $variantWarehouse->price = $warehouseOptions['price'] ?? 0;
                }

                $variantWarehouse->config = $config ?? null;
                $variantWarehouse->saveOrFail();

                $variant->addAttributes($this->mainAppCompany->user, [
                    [
                        'name' => 'color_code',
                        'value' => $colorCode,
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
        InventoryItem $netsuiteProductInfo,
        ?VariantsWarehouses $variantWarehouse = null,
        int|string|null $defaultWarehouse = null
    ): array {
        $config = [];

        try {
            $config['quantity'] = $this->productService->getInventoryQuantityByLocation(
                $netsuiteProductInfo,
                $variantWarehouse->get(CustomFieldEnum::NET_SUITE_LOCATION_ID->value) ?? $defaultWarehouse
            );

            $config['price'] = $this->productService->getProductPrice($netsuiteProductInfo);

            $config['minimum_quantity'] = $netsuiteProductInfo->minimumQuantity;
        } catch (Exception) {
            return $config;
        }

        return $config;
    }
}
