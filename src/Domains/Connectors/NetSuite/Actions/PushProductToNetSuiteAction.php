<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\InventoryItem;
use NetSuite\Classes\ItemLocationsList;
use NetSuite\Classes\InventoryItemLocations;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\StringCustomFieldRef;
use NetSuite\Classes\UpdateRequest;
use NetSuite\NetSuiteService;

/**
 * This action handles pushing product data from CSV TO NetSuite.
 * It's the inverse of PullNetSuiteProductPriceAction - instead of pulling
 * product data from NetSuite to Kanvas, it pushes product updates from
 * CSV directly to NetSuite (price, quantity, attributes, etc.)
 */
class PushProductToNetSuiteAction
{
    protected NetSuiteService $service;
    protected NetSuiteProductService $productService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
        protected UserInterface $user
    ) {
        $this->service = (new Client($app, $mainAppCompany))->getService();
        $this->productService = new NetSuiteProductService($app, $mainAppCompany);
    }

    /**
     * Push product data from CSV to NetSuite.
     *
     * @param string $barcode The product barcode/item number
     * @param array $productData Product data from CSV (price, quantity, etc.)
     * @param array $options Options for what data to sync
     * @return array Result of the sync operation
     */
    public function execute(string $barcode, array $productData, array $options = []): array
    {
        $defaultOptions = [
            'sync_price' => true,
            'sync_quantity' => true,
            'sync_attributes' => true,
        ];

        $options = array_merge($defaultOptions, $options);

        try {
            // API Call 1: Search for the product in NetSuite by barcode
            $searchResult = $this->productService->searchProductByItemNumber($barcode);

            if (empty($searchResult)) {
                return [
                    'success' => false,
                    'company' => $this->mainAppCompany->getId(),
                    'app' => $this->app->getId(),
                    'item' => $barcode,
                    'error' => 'Product not found in NetSuite',
                ];
            }

            $netsuiteProductId = $searchResult[0]->internalId;

            // Delay between API calls to avoid rate limiting (1 second)
            sleep(1);

            // Prepare the updated product (no need to get full product since we're only updating MOQ)
            $updatedProduct = $this->prepareProductUpdate($productData, $netsuiteProductId, $options);

            // API Call 2: Push the update to NetSuite
            $updateRequest = new UpdateRequest();
            $updateRequest->record = $updatedProduct;

            $response = $this->service->update($updateRequest);

            if (! $response->writeResponse->status->isSuccess) {
                throw new Exception(
                    'Error updating product in NetSuite: ' .
                    ($response->writeResponse->status->statusDetail[0]->message ?? 'Unknown error')
                );
            }

            return [
                'success' => true,
                'company' => $this->mainAppCompany->getId(),
                'app' => $this->app->getId(),
                'item' => $barcode,
                'netsuite_product_id' => $netsuiteProductId,
                'synced_fields' => array_keys(array_filter($options)),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'company' => $this->mainAppCompany->getId(),
                'app' => $this->app->getId(),
                'item' => $barcode,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prepare the product data for updating in NetSuite using CSV data.
     * Currently only updates MOQ (Minimum Order Quantity).
     */
    protected function prepareProductUpdate(
        array $productData,
        string|int $netsuiteProductId,
        array $options
    ): InventoryItem {
        $updatedProduct = new InventoryItem();
        $updatedProduct->internalId = $netsuiteProductId;

        // Only update MOQ custom field
        if (isset($productData['minimum_order_quantity'])) {
            $customFields = [];

            $moqField = new StringCustomFieldRef();
            $moqField->scriptId = CustomFieldEnum::NET_SUITE_MOQ_CUSTOM_FIELD->value;
            $moqField->value = (string) $productData['minimum_order_quantity'];
            $customFields[] = $moqField;

            $customFieldList = new CustomFieldList();
            $customFieldList->customField = $customFields;
            $updatedProduct->customFieldList = $customFieldList;
        }

        return $updatedProduct;
    }
}
