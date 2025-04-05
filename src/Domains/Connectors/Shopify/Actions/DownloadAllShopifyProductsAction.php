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
        $shopify = Client::getInstance(
            $this->app,
            $this->warehouses->company,
            $this->warehouses->region
        );

        $productsToImport = isset($params['sku']) && !empty($params['sku'])
            ? $this->getProductsBySku($shopify, trim($params['sku']))
            : $this->getAllProducts($shopify, $params);

        if (count($productsToImport)) {
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

        return count($productsToImport);
    }

    private function getProductsBySku($shopify, string $sku): array
    {
        $products = [];
        $shopifyP = $shopify->Product();
        $firstPage = null;

        do {
            $shopifyParams = !$firstPage && $shopifyP->getNextPageParams()
                ? $shopifyP->getNextPageParams()
                : ['limit' => 250];

            $shopifyProducts = $shopifyP->get($shopifyParams);

            foreach ($shopifyProducts as $product) {
                if (!isset($product['variants']) || !is_array($product['variants'])) {
                    continue;
                }

                foreach ($product['variants'] as $variant) {
                    if (isset($variant['sku']) && trim($variant['sku']) === $sku) {
                        $products[] = $this->mapProduct($product);
                        break;
                    }
                }
            }

            $firstPage = false;
        } while ($shopifyP->getNextPageParams());

        return $products;
    }

    private function getAllProducts($shopify, array $params): array
    {
        $products = [];
        $shopifyP = $shopify->Product();
        $firstPage = null;

        do {
            $shopifyParams = !$firstPage && $shopifyP->getNextPageParams()
                ? $shopifyP->getNextPageParams()
                : $params;

            $shopifyProducts = $shopifyP->get($shopifyParams);

            foreach ($shopifyProducts as $product) {
                $products[] = $this->mapProduct($product);
            }

            $firstPage = false;
        } while ($shopifyP->getNextPageParams());

        return $products;
    }

    private function mapProduct(array $shopifyProduct): array
    {
        $service = new ShopifyProductService(
            $this->app,
            $this->warehouses->company,
            $this->warehouses->region,
            $shopifyProduct['id'],
            $this->user,
            $this->warehouses,
            $this->channel
        );

        return $service->mapProductForImport($shopifyProduct);
    }
}