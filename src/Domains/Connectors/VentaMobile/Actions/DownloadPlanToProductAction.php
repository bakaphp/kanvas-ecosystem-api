<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\VentaMobile\Services\ProductService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;

class DownloadPlanToProductAction
{
    public function __construct(
        protected Regions $region,
        protected ?UserInterface $user = null,
        protected ?Warehouses $warehouses = null,
        protected ?Channels $channel = null
    ) {
        $this->user = $user ?? $this->region->company->user;
        $this->warehouses = $warehouses ?? Warehouses::fromCompany($this->region->company)->where('is_default', 1)->where('regions_id', $this->region->id)->firstOrFail();
        $this->channel = $channel ?? Channels::fromCompany($this->region->company)->where('is_default', 1)->firstOrFail();
    }

    public function execute(): array
    {
        $productService = new ProductService(
            $this->region->app,
            $this->region->company,
            $this->region
        );

        // Map VentaMobile products to Kanvas import format
        $products = $productService->mapProductsToImport();
        $jobUuid = Str::uuid()->toString();

        ProductImporterJob::dispatch(
            $jobUuid,
            $products,
            $this->region->company->defaultBranch,
            $this->user,
            $this->warehouses->region,
            $this->region->app
        );

        return $products;
    }
}
