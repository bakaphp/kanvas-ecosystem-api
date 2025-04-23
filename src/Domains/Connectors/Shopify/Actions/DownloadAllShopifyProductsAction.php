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
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use PHPShopify\ShopifySDK;

class DownloadAllShopifyProductsAction
{
    public function __construct(
        protected Apps $app,
        protected Warehouses $warehouses,
        protected CompaniesBranches $branch,
        protected Users $user,
        protected ?Channels $channel = null,
        protected ?Regions $region = null
    ) {
    }

    public function execute(array $params = []): int
    {
        $shopify = Client::getInstance(
            $this->app,
            $this->warehouses->company,
            $this->region ?? $this->warehouses->region
        );

        if (isset($params['sku']) && !empty($params['sku'])) {
            $productsToImport = $this->getProductsBySku($shopify, trim($params['sku']));
        } elseif (isset($params['product_id']) && !empty($params['product_id'])) {
            $productsToImport = $this->getProductById($shopify, trim($params['product_id']));
        } elseif (isset($params['handle']) && !empty($params['handle'])) {
            $productsToImport = $this->getProductByHandle($shopify, trim($params['handle']));
        } else {
            $productsToImport = $this->getAllProducts($shopify, $params);
        }

        if (count($productsToImport)) {
            $jobUuid = Str::uuid()->toString();

            ProductImporterJob::dispatch(
                $jobUuid,
                $productsToImport,
                $this->branch,
                $this->user,
                $this->region ?? $this->warehouses->region,
                $this->app
            );
        }

        return count($productsToImport);
    }

    private function getProductByHandle(ShopifySDK $shopify, string $handle): array
    {
        $products = [];

        // Using the Shopify API to query a product by its handle
        $shopifyProducts = $shopify->Product()->get([
            'handle' => $handle,
            'limit'  => 1,
        ]);

        // If a product is found with the given handle
        if (is_array($shopifyProducts) && count($shopifyProducts) > 0) {
            foreach ($shopifyProducts as $product) {
                if (isset($product['handle']) && $product['handle'] === $handle) {
                    $products[] = $this->mapProduct($product);

                    break; // We only need the first match
                }
            }
        }

        return $products;
    }

    private function getProductById(ShopifySDK $shopify, string $productId): array
    {
        $products = [];

        $shopifyProduct = $shopify->Product($productId)->get();

        if ($shopifyProduct) {
            $products[] = $this->mapProduct($shopifyProduct);
        }

        return $products;
    }

    private function getProductsBySku(ShopifySDK $shopify, string $sku): array
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

    private function getAllProducts(ShopifySDK $shopify, array $params): array
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
            $this->region ?? $this->warehouses->region,
            $shopifyProduct['id'],
            $this->user,
            $this->warehouses,
            $this->channel
        );

        return $service->mapProductForImport($shopifyProduct);
    }
}
