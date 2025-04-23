<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels;
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
class SyncNetSuiteCustomerItemsListAction
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

        $createNewChannel = new CreateChannel(
            new Channels(
                app: $this->app,
                company: $this->mainAppCompany,
                user: $this->mainAppCompany->user,
                name: $this->buyerCompany->name,
                description: $this->buyerCompany->name.' channel',
                slug: (string) $this->buyerCompany->uuid
            ),
            $this->mainAppCompany->user
        );
        $channel = $createNewChannel->execute();

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
                        'name'  => 'color_code',
                        'value' => $colorCode,
                    ],
                ]);
            } catch (Exception $e) {
                //$config['minimum_quantity'] = 0;
                $missed[] = $bardCodeId->item->name;
            }

            $addVariantToChannel = new AddVariantToChannelAction(
                $variantWarehouse,
                $channel,
                VariantChannel::from([
                    'price'            => $bardCodeId->price,
                    'discounted_price' => $bardCodeId->price,
                    'is_published'     => $bardCodeId->price > 0,
                    'config'           => $config ?? null,
                ])
            );
            $addVariantToChannel->execute();
            $totalProcessed++;
        }

        return [
            'channel'            => $channel->getId(),
            'company'            => $this->buyerCompany->getId(),
            'items'              => $listOrProductVariantsBarCodeIds,
            'total_items'        => count($listOrProductVariantsBarCodeIds),
            'total_processed'    => $totalProcessed,
            'total_missed'       => count($missed),
            'products_not_found' => $missed,
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
