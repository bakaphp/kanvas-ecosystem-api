<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Services\ShopifyProductService;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class SyncShopifyProductAction
{
    protected array $files;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected string|int $productId,
        protected ?UserInterface $user = null,
        protected ?Warehouses $warehouses = null
    ) {
        $this->user = $user ?? $this->company->user;
        $this->warehouses = $warehouses ?? Warehouses::fromCompany($this->company)->where('is_default', 1)->where('regions_id', $this->region->id)->firstOrFail();
    }

    public function execute(): Products
    {
        $shopify = Client::getInstance(
            $this->app,
            $this->company,
            $this->region
        );

        $shopifyProduct = $shopify->Product($this->productId)->get();

        $shopifyProductService = new ShopifyProductService(
            $this->app,
            $this->company,
            $this->region,
            $this->productId,
            $this->user,
            $this->warehouses
        );

        $productsToImport = $shopifyProductService->mapProduct($shopifyProduct);

        return (
            new ProductImporterAction(
                ProductImporter::from($productsToImport),
                $this->company,
                $this->user,
                $this->region,
                $this->app
            ))->execute();

    }
}
