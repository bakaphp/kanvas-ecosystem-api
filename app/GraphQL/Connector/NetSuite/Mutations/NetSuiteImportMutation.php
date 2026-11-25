<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\NetSuite\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ImportNetSuiteLocationsAction;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class NetSuiteImportMutation
{
    use ResolvesActingContext;

    /**
     * @return list<Warehouses>
     */
    public function importLocations(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        /** @var Companies $company */
        $company = $ctx->company;

        return new ImportNetSuiteLocationsAction($ctx->app, $company, $ctx->user)->execute(
            (string) $input['default_location_id'],
            array_map('strval', $input['location_ids'] ?? [])
        );
    }
}
