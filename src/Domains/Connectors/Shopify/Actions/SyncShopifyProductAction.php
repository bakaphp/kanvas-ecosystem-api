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
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;

class SyncShopifyProductAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected string|int $productId,
        protected ?UserInterface $user = null,
        protected ?Warehouses $warehouses = null,
        protected bool $runWorkflow = true,
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

        return $this->import($shopify->Product($this->productId)->get());
    }

    /**
     * For a caller that already holds the product as Shopify returns it.
     */
    public function import(array $shopifyProduct): Products
    {
        $shopifyProductService = new ShopifyProductService(
            $this->app,
            $this->company,
            $this->region,
            $this->productId,
            $this->user,
            $this->warehouses
        );

        return new ProductImporterAction(
            importedProduct: ProductImporter::from($shopifyProductService->mapProductForImport($shopifyProduct)),
            company: $this->company,
            user: $this->user,
            region: $this->region,
            app: $this->app,
            runWorkflow: $this->runWorkflow,
        )->execute();
    }
}
