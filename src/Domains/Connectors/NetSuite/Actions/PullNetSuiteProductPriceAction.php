<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Inventory\Variants\Models\Variants;
use NetSuite\Classes\InventoryItem;

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
    protected NetSuiteProductSearchService $searchService;
    protected bool $shouldUseLegacySearch = false;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
        protected UserInterface $user
    ) {
        $this->service = new NetSuiteCustomerService($app, $mainAppCompany);
        $this->productService = new NetSuiteProductService($app, $mainAppCompany);
        $this->shouldUseLegacySearch = (bool) $this->app->get(ConfigurationEnum::NET_SUITE_USE_LEGACY_PRODUCT_SEARCH->value);
        $this->searchService = new NetSuiteProductSearchService($app, $mainAppCompany);
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
                'app' => $this->app->getId(),
                'item' => $barcode,
                'error' => 'Product not found',
            ];
        }

        $variantWarehouse = $variant->variantWarehouses()->firstOrFail();

        $warehouseOptions = $this->getWarehouseOptions($netsuiteProductInfo);
        $locationId = $variantWarehouse->get(CustomFieldEnum::NET_SUITE_LOCATION_ID->value) ?? $defaultWarehouse;

        // @todo: We should unify and extract this logic into its own action later
        $product = $this->shouldUseLegacySearch
        ? $this->legacySearchProductByItemNumber($netsuiteProductInfo, $locationId)
        : $this->customSearchProductByItemNumber($variant->barcode, $locationId);

        if (empty($product)) {
            return [
                'company' => $this->mainAppCompany->getId(),
                'app' => $this->app->getId(),
                'item' => $barcode,
                'error' => 'Product not found',
            ];
        }

        $config = [
            'map_price' => $product["map_price"],
            ...(isset($product['minimum_quantity']) && $setMinimumQuantity ? ['minimum_quantity' => $product['minimum_quantity']] : []),
        ];

        $variantWarehouse->quantity = $product['quantity_available'] ?? 0;
        $variantWarehouse->price = $warehouseOptions['price'] ?? 0;

        $variantWarehouse->config = $config;
        $variantWarehouse->saveOrFail();

        $variant->addAttributes($this->user, [
            [
                'name' => 'color_code',
                'value' => $product["color_code"],
            ],
            [
                'name' => 'minimum_order_quantity',
                'value' => $product["minimum_order_quantity"] ?? 0,
            ],
        ]);

        $variant->set(CustomFieldEnum::NET_SUITE_PRODUCT_ID->value, $searchNetsuiteProductInfo[0]->internalId);

        return [
            'company' => $this->mainAppCompany->getId(),
            'item' => $barcode,
            'config' => $config,
            'options' => $warehouseOptions,
            'product' => $product,
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
        int|string|null $locationId = null
    ): array {
        $mapPrice = (float) $this->productService->getCustomField($netsuiteProductInfo, CustomFieldEnum::NET_SUITE_MAP_PRICE_CUSTOM_FIELD->value);
        $colorCode = $this->productService->getCustomField($netsuiteProductInfo, CustomFieldEnum::NET_SUITE_COLOR_CODE_CUSTOM_FIELD->value);
        $minimumOrderQuantity = $this->productService->getCustomField($netsuiteProductInfo, CustomFieldEnum::NET_SUITE_MOQ_CUSTOM_FIELD->value);

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
            'minimum_order_quantity' => (int) $minimumOrderQuantity,
        ];
    }


    private function customSearchProductByItemNumber(string $itemNumber, int|string|null $locationId = null): array
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
            'minimum_order_quantity' => (int) $searchProduct[0]['moq'],
        ];
    }
}
