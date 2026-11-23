<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\NetSuite\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteImportStatusService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationWarehouseService;

class NetSuiteImportQuery
{
    use ResolvesActingContext;

    public function status(mixed $root, array $request): ?array
    {
        /** @var Companies $company */
        $company = $this->actingContext()->company;

        $status = new NetSuiteImportStatusService($company, ImportEntityEnum::from($request['entity']))
            ->current();

        // A stored status may lack location_ids, which the schema declares non-null.
        return $status === null ? null : ['location_ids' => [], ...$status];
    }

    public function locations(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $mapping = new NetSuiteLocationWarehouseService($ctx->app, $ctx->company);
        $mapped = $mapping->explicitMap();

        return array_map(
            fn (array $location) => [...$location, 'warehouse' => $mapping->warehouseFor($location['id'], $mapped)],
            new NetSuiteLocationService($ctx->app, $ctx->company)->active()
        );
    }
}
