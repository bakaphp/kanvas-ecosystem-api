<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\CMLink\Services\CarrierService;
use Kanvas\Connectors\CMLink\Services\CMLinkProductService;
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

    public function execute(string $language = '2', int $totalPages = 12): array
    {
        $carrierService = new CarrierService($this->region->app, $this->region->company);

        $productsToImport = [];
        $productService = new CMLinkProductService(
            $this->region,
            $this->user,
            $this->warehouses,
            $this->channel
        );

        $allImports = [];
        for ($i = 0; $i <= $totalPages; $i++) {
            $bundles = $carrierService->getAllDataBundle($language, $i);

            if (empty($bundles)) {
                continue;
            }

            $jobUuid = Str::uuid()->toString();

            $productsToImport = $productService->mapProductToImport($bundles);
            $allImports[] = $productsToImport;

            ProductImporterJob::dispatch(
                $jobUuid,
                $productsToImport,
                $this->region->company->defaultBranch,
                $this->user,
                $this->warehouses->region,
                $this->region->app
            );
        }

        return $allImports;
    }
}
