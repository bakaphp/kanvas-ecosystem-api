<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\ESim\Services\DestinationService;
use Kanvas\Connectors\ESim\Services\ESimProductService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

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

    public function execute(array $destinationPlans): void
    {
        $destination = new DestinationService($this->region->app, $this->region->company);

        if (! isset($destinationPlans[0]['code'])) {
            throw new ValidationException('Invalid plan list missing code');
        }

        $productsToImport = [];
        $esimProductService = new ESimProductService(
            $this->region,
            $this->user,
            $this->warehouses,
            $this->channel
        );

        foreach ($destinationPlans as $destinationPlan) {
            $destinationPlanResult = $destination->getPlans(
                code: $destinationPlan['code'],
                limit: $destinationPlan['limit'] ?? 25,
                page: $destinationPlan['page'] ?? 1
            );

            $jobUuid = Str::uuid()->toString();

            foreach ($destinationPlanResult['data'] as $plan) {
                $productsToImport[] = $esimProductService->mapProductToImport($plan);
            }

            ProductImporterJob::dispatch(
                $jobUuid,
                $productsToImport,
                $this->region->company->defaultBranch,
                $this->user,
                $this->warehouses->region,
                $this->region->app
            );
        }
    }
}
