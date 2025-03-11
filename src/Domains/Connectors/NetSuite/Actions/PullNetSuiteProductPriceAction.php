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
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * This action handles the synchronization of the NetSuite Customer Items List,
 * which essentially represents the products that a specific customer or company
 * has access to, along with their specific pricing. The process involves taking
 * this list of products, locating them within the main B2B company database,
 * and creating a dedicated channel for the customer. This enables the promotion
 * of these products to the customer effectively.
 */
class PullNetSuiteProductPriceAction
{
    protected NetSuiteCustomerService $service;
    protected NetSuiteProductService $productService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
    ) {
        $this->service = new NetSuiteCustomerService($app, $mainAppCompany);
        $this->productService = new NetSuiteProductService($app, $mainAppCompany);
    }

    public function execute(string $barcode): array
    {
        $searchNetsuiteProductInfo = $this->productService->searchProductByItemNumber($barcode);
        $netsuiteProductInfo = $this->productService->getProductById($searchNetsuiteProductInfo[0]->internalId);

        $setMinimumQuantity = $this->app->get(ConfigurationEnum::NET_SUITE_MINIMUM_PRODUCT_QUANTITY->value);
        $defaultWarehouse = $this->mainAppCompany->get(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value);

        $variant = Variants::fromApp($this->app)
                ->fromCompany($this->mainAppCompany)
                ->where('barcode', $barcode)
                ->first();

        if (! $variant) {
            return [
            'company' => $this->mainAppCompany->getId(),
            'item' => $barcode,
            'error' => 'Product not found',
            ];
        }

        $variantWarehouse = $variant->variantWarehouses()->firstOrFail();

        if ($setMinimumQuantity) {
            $warehouseOptions = $this->getWarehouseOptions($netsuiteProductInfo, $variantWarehouse, $defaultWarehouse);
        }

        $mapPrice =  $this->productService->getProductMapPrice($netsuiteProductInfo, CustomFieldEnum::NET_SUITE_MAP_PRICE_CUSTOM_FIELD->value);

        $config = [
            'map_price' => $mapPrice,
            ...(isset($warehouseOptions["minimum_quantity"]) ? ["minimum_quantity" => $warehouseOptions["minimum_quantity"]] : []),
        ];

        if (isset($warehouseOptions["quantity"]) && $warehouseOptions["quantity"] !== null) {
            $variantWarehouse->quantity = $warehouseOptions["quantity"];
            $variantWarehouse->price = $warehouseOptions["price"] ?? 0;
            $variantWarehouse->config =  $config ?? null;
            $variantWarehouse->saveOrFail();
        }

        return [
            'company' => $this->mainAppCompany->getId(),
            'item' => $barcode,
        ];
    }


    private function getWarehouseOptions($netsuiteProductInfo, $variantWarehouse = null, $defaultWarehouse = null)
    {
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
